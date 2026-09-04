"""
Vietnamese OCR microservice

PaddleOCR 3.x:
    - Phát hiện vị trí chữ

VietOCR:
    - Nhận dạng nội dung từng vùng chữ

Tối ưu CPU:
    1. Bật MKL-DNN
    2. Tắt textline orientation
    3. Tắt document orientation
    4. Tắt document unwarping
    5. Không upscale ảnh nhỏ lên 1500/1600
    6. Giới hạn ảnh tối đa khoảng 1000px
    7. Tắt bilateralFilter mặc định
    8. Giữ VietOCR để đọc nội dung

Xử lý concurrency (THÊM MỚI):
    - PaddleOCR/VietOCR không đảm bảo thread-safe khi 2 thread cùng
      gọi .predict() trên chung 1 instance -> dùng threading.Lock
      để khóa riêng từng model.
    - Route là async def nhưng code xử lý là code đồng bộ (blocking) ->
      nếu không đẩy ra threadpool thì cả server bị treo trong lúc OCR.
      Dùng run_in_executor để đẩy phần xử lý nặng ra thread riêng,
      trong khi model vẫn được bảo vệ bởi lock ở trên.

Chuẩn hoá shape đầu vào (THÊM MỚI):
    - PaddleOCR (đặc biệt khi bật enable_mkldnn) build lại computation
      graph/cache mỗi khi shape ảnh đầu vào thay đổi -> detect có thể
      chậm bất thường (hàng trăm giây thay vì vài giây) nếu mỗi request
      có kích thước ảnh khác nhau (điều này rất hay xảy ra với PDF, vì
      mỗi trang render ra 1 kích thước khác nhau tuỳ khổ giấy gốc).
    - Giải pháp: LUÔN resize + pad ảnh về đúng 1 kích thước cố định
      (DET_TARGET_SIZE x DET_TARGET_SIZE) trước khi đưa vào
      ocr_engine.predict(), cho MỌI request (/ocr lẫn /ocr-pdf). Nhờ vậy
      PaddleOCR chỉ cần "làm quen" với 1 shape duy nhất trong suốt vòng
      đời server, tốc độ ổn định và nhanh ngay từ request đầu tiên sau
      warm-up.

Bỏ qua VietOCR cho trang không cần dùng (THÊM MỚI):
    - PDF do sinh viên gộp thường chứa nhiều loại giấy tờ trong 1 file
      (CCCD, bằng THPT, bảng điểm, bằng trung cấp/CĐ/ĐH...), nhưng mỗi
      lần upload chỉ cần lấy ĐÚNG 1 loại (theo document_type đã chọn ở
      form Laravel).
    - PaddleOCR predict() đã tự đọc luôn nội dung thô (field rec_texts)
      mà KHÔNG tốn thêm chi phí gì (đã có sẵn trong lúc detect). Ta dùng
      luôn text thô này để đoán NHANH xem trang hiện tại thuộc loại giấy
      tờ gì, rồi chỉ chạy VietOCR (bước CHẬM - trung bình ~3s/vùng chữ)
      cho những trang thực sự cần, bỏ qua hẳn VietOCR cho trang không
      liên quan (bảng điểm, học bạ, bằng THPT khi đang cần CCCD, v.v).
    - Nếu không xác định được document_type, hoặc không đọc được
      rec_texts từ kết quả PaddleOCR (tuỳ phiên bản có thể đặt tên field
      khác), CỐ TÌNH fallback về chạy VietOCR cho MỌI trang như hành vi
      cũ — thà chậm còn hơn bỏ sót dữ liệu cần dùng do đoán sai.
"""

import os

# Tránh lỗi OpenMP trên Windows
os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

# LƯU Ý: KHÔNG set OMP_NUM_THREADS / MKL_NUM_THREADS ở đây.
# Bản PaddlePaddle cài qua pip trên Windows build với OpenBLAS, vốn
# KHÔNG hỗ trợ đa luồng kiểu này ("It will fail if this PaddlePaddle
# binary is compiled with OpenBlas since OpenBlas does not support
# multi-threads" — warning thực tế gặp phải khi set 2 biến này).
# Ép set giá trị (kể cả =2) gây tranh chấp thread nội bộ tích luỹ dần
# qua các lần gọi predict() liên tiếp -> detect chậm DẦN theo thời gian
# server chạy (22s -> 24s -> 64s -> 144s...), không phải do shape ảnh
# hay do dữ liệu. Đã xác nhận qua log thực tế: bỏ 2 dòng này là hết
# hiện tượng tăng dần.

import io
import time
import asyncio
import threading
import unicodedata
from concurrent.futures import ThreadPoolExecutor

import cv2
import numpy as np

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from PIL import Image, ImageOps, ImageEnhance

import pymupdf as fitz

from paddleocr import PaddleOCR

from vietocr.tool.predictor import Predictor
from vietocr.tool.config import Cfg


# ============================================================
# CẤU HÌNH
# ============================================================

# Ảnh quá lớn sẽ được thu nhỏ xuống tối đa khoảng 1400px
MAX_SIDE = 1400

# Không dùng upscale ảnh nhỏ
ENABLE_DENOISE = False

# Tắt lưu ảnh debug để giảm I/O
SAVE_DEBUG_IMAGES = False

# THÊM MỚI: số thread tối đa xử lý OCR song song.
# Vì bên trong vẫn bị lock khi gọi model, con số này chủ yếu quyết định
# preprocess (resize, crop, contrast...) có thể chạy song song bao nhiêu
# request cùng lúc trước khi tới đoạn phải xếp hàng chờ model.
MAX_WORKERS = 4

# THÊM MỚI: bật để in ra lý do 1 vùng chữ bị loại bỏ (score thấp / box quá
# nhỏ / VietOCR trả rỗng) — dùng để chẩn đoán khi bị mất chữ (vd mất
# "Nam"/"Nữ" ở mục Giới tính trên CCCD). Xong việc thì tắt lại (False)
# để đỡ rác log khi chạy production.
DEBUG_FILTER = True

# THÊM MỚI: kích thước cố định (vuông) đưa vào PaddleOCR detect. PHẢI
# khớp với text_det_limit_side_len bên dưới để tránh việc PaddleOCR tự
# resize lại nội bộ (gây lệch toạ độ box so với ảnh dùng để crop cho
# VietOCR). Đây là fix chính cho vấn đề detect chậm bất thường (xem
# giải thích ở docstring đầu file).
DET_TARGET_SIZE = 960

# THÊM MỚI: mỗi document_type Laravel gửi xuống chỉ CẦN các loại trang
# nào (đầu ra của classify_page_quick()). Trang không thuộc tập này sẽ
# bị skip VietOCR để tiết kiệm thời gian.
NEEDED_TYPES_BY_DOCTYPE = {
    "cccd_front": {"cccd"},
    "cccd_back": {"cccd"},
    "admission_form": {"phieu_dang_ky"},
    "diploma_transcript": {"bang_dai_hoc", "bang_cao_dang", "bang_trung_cap"},
}


# ============================================================
# FASTAPI
# ============================================================

app = FastAPI(
    title="Vietnamese OCR Service"
)

# THÊM MỚI: threadpool riêng để chạy phần xử lý blocking (preprocess + OCR),
# tách khỏi threadpool mặc định của Starlette cho gọn và dễ kiểm soát.
executor = ThreadPoolExecutor(max_workers=MAX_WORKERS)


# ============================================================
# KHỞI TẠO PADDLEOCR
# ============================================================

print("=" * 70)
print("DANG KHOI TAO PADDLEOCR...")
print("=" * 70)

ocr_engine = PaddleOCR(
    lang="vi",

    # Không cần xoay từng dòng nếu giấy tờ đã tương đối thẳng
    use_textline_orientation=False,

    # Không cần tự xoay toàn bộ tài liệu
    use_doc_orientation_classify=False,

    # Không cần unwarp tài liệu
    use_doc_unwarping=False,

    # SỬA: tắt mkldnn — bật mkldnn khiến PaddleOCR build lại
    # computation graph mỗi khi shape ảnh đầu vào thay đổi, gây detect
    # chậm bất thường (100-200s thay vì vài giây). Sau khi đã chuẩn hoá
    # shape đầu vào cố định (resize_and_pad), có thể thử bật lại mkldnn
    # để so sánh tốc độ, nhưng hiện tại để False cho ổn định.
    enable_mkldnn=False,

    # Giảm kích thước xử lý detection — PHẢI khớp DET_TARGET_SIZE
    text_det_limit_side_len=DET_TARGET_SIZE,

    # THÊM MỚI: nới ngưỡng detection để bắt được chữ nhỏ/mảnh
    # (vd "Nam"/"Nữ" trên CCCD) — mặc định PaddleOCR đôi khi bỏ sót
    # các vùng chữ ngắn, ít pixel. Giá trị càng thấp càng "nhạy" hơn
    # nhưng có thể bắt thêm nhiễu, cần cân nhắc test lại.
    text_det_thresh=0.2,        # mặc định thường ~0.3
    text_det_box_thresh=0.4,    # mặc định thường ~0.6
)

print("PaddleOCR khoi tao OK.")

# THÊM MỚI: lock riêng cho PaddleOCR. Mọi lệnh gọi ocr_engine.predict()
# trong toàn bộ file đều phải nằm trong "with ocr_lock:".
ocr_lock = threading.Lock()


# ============================================================
# CHUẨN HOÁ SHAPE ĐẦU VÀO (THÊM MỚI)
# ============================================================

def resize_and_pad(
    img_array: np.ndarray,
    target: int = DET_TARGET_SIZE
):
    h, w = img_array.shape[:2]

    scale = target / max(h, w)

    new_w = max(1, int(w * scale))
    new_h = max(1, int(h * scale))

    resized = cv2.resize(
        img_array,
        (new_w, new_h),
        interpolation=(
            cv2.INTER_AREA
            if scale < 1
            else cv2.INTER_LANCZOS4
        ),
    )

    canvas = np.full(
        (target, target, 3),
        255,
        dtype=np.uint8
    )

    canvas[0:new_h, 0:new_w] = resized

    return canvas, scale, new_w, new_h
def scale_poly_to_original(
    poly,
    scale: float,
    original_width: int,
    original_height: int
):
    poly_np = np.array(
        poly,
        dtype=np.float32
    )

    # Box từ ảnh 960x960
    # → chuyển ngược về ảnh preprocess gốc
    poly_np = poly_np / scale

    poly_np[:, 0] = np.clip(
        poly_np[:, 0],
        0,
        original_width - 1
    )

    poly_np[:, 1] = np.clip(
        poly_np[:, 1],
        0,
        original_height - 1
    )

    return poly_np

# ============================================================
# WARM-UP PADDLEOCR
# ============================================================

print(
    "Dang warm-up PaddleOCR "
    "(co the mat vai chuc giay lan dau, chi chay 1 lan)..."
)

_warmup_t0 = time.time()

try:
    _dummy_img = np.full(
        (800, 800, 3),
        255,
        dtype=np.uint8,
    )

    # SỬA: warm-up phải đi qua đúng resize_and_pad() giống hệt luồng
    # thật, để shape được "làm nóng" khớp với shape mọi request sau này
    # sẽ dùng.
    _dummy_padded, _, _, _ = resize_and_pad(_dummy_img)

    with ocr_lock:
        _ = list(
            ocr_engine.predict(_dummy_padded)
        )

    print(
        f"Warm-up PaddleOCR xong: "
        f"{time.time() - _warmup_t0:.2f}s"
    )

except Exception as _exc:
    print(
        "Warm-up PaddleOCR loi "
        f"(bo qua, se thu lai o request dau): {_exc}"
    )

print("=" * 70)


# ============================================================
# KHỞI TẠO VIETOCR
# ============================================================

print("DANG KHOI TAO VIETOCR...")
print("=" * 70)

VIETOCR_MODEL_NAME = "vgg_transformer"
vietocr_config = Cfg.load_config_from_name(VIETOCR_MODEL_NAME)

# FIX SSL: vocr.vn đã hết hạn chứng chỉ. Chuyển URL cấu hình sang GitHub.
from vietocr.tool import config as vietocr_config_module

vietocr_config_module.url_config["base"] = (
    "https://raw.githubusercontent.com/pbcquoc/vietocr/master/config/base.yml"
)
vietocr_config_module.url_config[VIETOCR_MODEL_NAME] = (
    f"https://raw.githubusercontent.com/pbcquoc/vietocr/master/config/{VIETOCR_MODEL_NAME}.yml"
)

vietocr_config = Cfg.load_config_from_name(
    VIETOCR_MODEL_NAME
)

# Chạy CPU
vietocr_config["device"] = "cpu"

vietocr_engine = Predictor(
    vietocr_config
)

print(
    f"VietOCR ({VIETOCR_MODEL_NAME}) khoi tao OK."
)

# THÊM MỚI: lock riêng cho VietOCR. Mọi lệnh gọi vietocr_engine.predict()
# trong toàn bộ file đều phải nằm trong "with vietocr_lock:".
vietocr_lock = threading.Lock()


# ============================================================
# WARM-UP VIETOCR
# ============================================================

try:

    _dummy_pil = Image.new(
        "RGB",
        (100, 32),
        (255, 255, 255),
    )

    with vietocr_lock:
        _ = vietocr_engine.predict(
            _dummy_pil
        )

    print("Warm-up VietOCR xong.")

except Exception as _exc:

    print(
        f"Warm-up VietOCR loi "
        f"(bo qua): {_exc}"
    )


print("=" * 70)


# ============================================================
# PREPROCESS
# ============================================================

def preprocess(image: Image.Image) -> np.ndarray:

    # --------------------------------------------------------
    # 1. Xử lý orientation của ảnh
    # --------------------------------------------------------

    image = ImageOps.exif_transpose(image)

    image = image.convert("RGB")


    # --------------------------------------------------------
    # 2. Lấy kích thước
    # --------------------------------------------------------

    w, h = image.size


    # --------------------------------------------------------
    # 3. Cắt nhẹ phần viền
    # --------------------------------------------------------

    margin_w = int(w * 0.05)
    margin_h = int(h * 0.05)

    image = image.crop(
        (
            margin_w,
            margin_h,
            w - margin_w,
            h - margin_h,
        )
    )


    # --------------------------------------------------------
    # 4. Chỉ THU NHỎ ảnh lớn
    #
    # Không upscale ảnh nhỏ nữa.
    # --------------------------------------------------------

    w, h = image.size

    longest_side = max(w, h)

    if longest_side > MAX_SIDE:

        scale = MAX_SIDE / longest_side

        image = image.resize(
            (
                int(w * scale),
                int(h * scale),
            ),
            Image.LANCZOS,
        )


    # --------------------------------------------------------
    # 5. Tăng contrast / sharpness nhẹ
    # --------------------------------------------------------

    image = ImageEnhance.Contrast(
        image
    ).enhance(1.15)

    image = ImageEnhance.Sharpness(
        image
    ).enhance(1.15)


    # --------------------------------------------------------
    # 6. PIL -> NumPy
    # --------------------------------------------------------

    img_array = np.array(image)


    # --------------------------------------------------------
    # 7. Denoise tùy chọn
    #
    # Mặc định False vì bilateralFilter vẫn tốn CPU.
    # --------------------------------------------------------

    if ENABLE_DENOISE:

        img_array = cv2.bilateralFilter(
            img_array,
            d=5,
            sigmaColor=50,
            sigmaSpace=50,
        )


    # --------------------------------------------------------
    # 8. Trả về ảnh
    # --------------------------------------------------------

    return img_array


# ============================================================
# HELPER
# ============================================================

def _get(
    res,
    key,
    default=None
):
    """
    Truy cập an toàn cả dict lẫn object PaddleX.
    """

    try:

        value = res[key]

        return (
            value
            if value is not None
            else default
        )

    except (
        KeyError,
        TypeError,
        IndexError
    ):

        return default


# ============================================================
# PHÂN LOẠI NHANH TRANG (THÊM MỚI)
# ============================================================

def strip_diacritics_vn(text: str) -> str:
    """
    Chuẩn hoá text tiếng Việt: viết thường + bỏ dấu, để so khớp keyword
    khoan dung lỗi/thiếu dấu do OCR. Tương đương normalizeText() bên
    Laravel (FileTextExtractorService::normalizeText()) — giữ đồng bộ
    logic 2 bên.
    """

    text = text.lower()
    text = text.replace("đ", "d")
    text = unicodedata.normalize("NFD", text)
    text = "".join(
        ch for ch in text
        if unicodedata.category(ch) != "Mn"
    )

    return text


def get_quick_text(res) -> str:
    """
    Lấy text thô mà chính PaddleOCR đã tự đọc được trong lúc detect
    (KHÔNG tốn thêm chi phí gì — dữ liệu này có sẵn trong kết quả
    predict()). Dùng để đoán NHANH loại trang trước khi quyết định có
    chạy VietOCR (chậm, chính xác hơn) hay không.

    Thử nhiều tên field khác nhau vì các phiên bản PaddleOCR/PaddleX
    có thể đặt tên field hơi khác nhau (rec_texts là tên phổ biến ở
    PaddleOCR 3.x, nhưng để an toàn vẫn thử thêm vài field khác).
    """

    for key in ("rec_texts", "texts", "text"):
        value = _get(res, key, None)

        if not value:
            continue

        try:
            if isinstance(value, str):
                return value

            return " ".join(str(v) for v in value if v)
        except Exception:
            continue

    return ""


def classify_page_quick(quick_text: str) -> str:
    """
    Phân loại NHANH 1 trang PDF thành 1 trong các nhóm: 'cccd',
    'phieu_dang_ky', 'bang_dai_hoc', 'bang_cao_dang', 'bang_trung_cap',
    hoặc 'khac' (loại bỏ — bảng điểm, học bạ, bằng THPT, mặt sau CCCD...).

    Dựa trên text THÔ do chính PaddleOCR đọc được (chưa qua VietOCR),
    nên độ chính xác không cao bằng classifyPage() phía Laravel (vốn
    chạy trên text VietOCR đã tinh chỉnh) — nhưng KHÔNG SAO, vì mục
    đích ở đây chỉ là quyết định NHANH có đáng chạy VietOCR (bước chậm)
    cho trang này hay không. Laravel vẫn sẽ tự phân loại lại lần nữa
    trên text VietOCR thật (đã chạy) làm lớp lọc chính xác cuối cùng.

    LƯU Ý: mặt sau CCCD (vân tay, đặc điểm nhận dạng...) KHÔNG chứa dữ
    liệu cần dùng, nên CỐ TÌNH không nhận diện riêng — để nó rơi vào
    "khac" và bị skip VietOCR như các trang không cần khác.
    """

    t = strip_diacritics_vn(quick_text)

    # ===== 1. CCCD (chỉ mặt trước — có nhãn rõ ràng) =====
    if (
        "can cuoc cong dan" in t
        or "citizen identity card" in t
    ):
        return "cccd"

    # ===== 2. Phiếu đăng ký xét tuyển / dự tuyển =====
    if "phieu dang ky" in t and "tuyen" in t:
        return "phieu_dang_ky"

    # ===== 3. LOẠI TRỪ TƯỜNG MINH (trước khi thử match bằng cấp) =====
    if "trung hoc pho thong" in t and "bang" in t:
        return "khac"

    for keyword in ("bang diem", "ket qua hoc tap", "diem trung binh", "hoc ba"):
        if keyword in t:
            return "khac"

    # ===== 4. Bằng Đại học =====
    if any(
        keyword in t
        for keyword in ("bang dai hoc", "bang cu nhan", "bang ky su")
    ):
        return "bang_dai_hoc"

    # ===== 5. Bằng Cao đẳng =====
    if "bang cao dang" in t:
        return "bang_cao_dang"

    # ===== 6. Bằng Trung cấp =====
    if (
        any(
            keyword in t
            for keyword in ("bang tot nghiep trung cap", "bang trung cap")
        )
        or ("diploma" in t and "trung cap" in t)
    ):
        return "bang_trung_cap"

    return "khac"


# ============================================================
# PADDLEOCR DETECTION
# +
# VIETOCR RECOGNITION
# ============================================================

def extract_lines(
    res,
    detect_image: np.ndarray,
    recognition_image: np.ndarray,
    det_scale: float,
    min_score: float = 0.3,
    min_box_area: int = 100
) -> list[dict]:

    """
    PaddleOCR:
        Phát hiện vị trí chữ.

    VietOCR:
        Nhận dạng nội dung từng vùng chữ.
    """

    # ========================================================
    # 1. ƯU TIÊN LẤY BOX DETECTION
    # ========================================================

    polys = _get(res, "dt_polys")

    if polys is None:
        polys = _get(res, "rec_polys")

    if polys is None:
        polys = []


    # ========================================================
    # 2. LẤY SCORE
    # ========================================================

    scores = _get(
        res,
        "dt_scores",
        None
    )


    lines = []

    if DEBUG_FILTER:

        print(
            f"[DEBUG] Tong so vung chu PaddleOCR phat hien: "
            f"{len(polys)}"
        )

        if scores is not None:

            try:

                print(
                    "[DEBUG] Scores:",
                    [
                        round(float(score), 2)
                        for score in scores
                    ]
                )

            except Exception:

                pass


    # ========================================================
    # 3. DUYỆT TỪNG BOX
    # ========================================================

    for i, poly in enumerate(polys):


        # ----------------------------------------------------
        # Lọc confidence
        # ----------------------------------------------------

        if (
            scores is not None
            and i < len(scores)
        ):

            try:

                score = float(scores[i])

                if score < min_score:

                    if DEBUG_FILTER:

                        print(
                            f"[FILTER] Box {i} bi loai "
                            f"do score thap: {score:.2f}"
                        )

                    continue

            except Exception:

                pass


        # ----------------------------------------------------
        # Chuyển polygon sang numpy
        # ----------------------------------------------------

        try:
            # Box PaddleOCR trả về đang thuộc tọa độ detect_image
            box_detect = np.array(
                poly,
                dtype=np.float32
            )

            # Scale box ngược về recognition_image
            box_np = scale_poly_to_original(
                box_detect,
                det_scale,
                recognition_image.shape[1],
                recognition_image.shape[0]
            )

        except Exception as exc:
            if DEBUG_FILTER:
                print(
                    f"[FILTER] Loi scale box {i}: {exc}"
                )
            continue


        # ====================================================
        # Phải có ít nhất 4 điểm
        # ====================================================

        if box_np.shape[0] < 4:
            if DEBUG_FILTER:
                print(
                    f"[FILTER] Box {i} khong du 4 diem"
                )
            continue


        # ====================================================
        # TÍNH BOUNDING BOX ĐỂ KIỂM TRA
        #
        # LƯU Ý:
        # box_np lúc này đã thuộc tọa độ recognition_image
        # ====================================================

        x_min = max(
            0,
            int(np.min(box_np[:, 0]))
        )

        y_min = max(
            0,
            int(np.min(box_np[:, 1]))
        )

        x_max = min(
            recognition_image.shape[1],
            int(np.max(box_np[:, 0]))
        )

        y_max = min(
            recognition_image.shape[0],
            int(np.max(box_np[:, 1]))
        )


        width = x_max - x_min
        height = y_max - y_min


        # ====================================================
        # KIỂM TRA BOX HỢP LỆ
        # ====================================================

        if width <= 0 or height <= 0:
            if DEBUG_FILTER:
                print(
                    f"[FILTER] Box {i} khong hop le "
                    f"| width={width}, height={height}"
                )
            continue


        area = width * height


        # ----------------------------------------------------
        # Bỏ box quá nhỏ
        # ----------------------------------------------------

        if area < min_box_area:

            if DEBUG_FILTER:
                print(
                    f"[FILTER] Box {i} qua nho "
                    f"({area}px) "
                    f"| x={x_min}-{x_max}, "
                    f"y={y_min}-{y_max}"
                )

            continue

        # ====================================================
        # 4. RECTIFY BOX
        #
        # Dùng perspective transform thay vì crop hình chữ nhật
        #
        # Đây là phần quan trọng để tránh CCCD bị crop sai
        # ====================================================

        try:

            rect = cv2.minAreaRect(
                box_np.astype(np.float32)
            )

            box = cv2.boxPoints(
                rect
            )

            box = box.astype(
                np.float32
            )


            # Sắp xếp 4 điểm:
            # top-left
            # top-right
            # bottom-right
            # bottom-left

            ordered = np.zeros(
                (4, 2),
                dtype=np.float32
            )

            s = box.sum(axis=1)

            ordered[0] = box[np.argmin(s)]
            ordered[2] = box[np.argmax(s)]

            diff = np.diff(
                box,
                axis=1
            )

            ordered[1] = box[np.argmin(diff)]
            ordered[3] = box[np.argmax(diff)]


            # Tính chiều rộng

            width_a = np.linalg.norm(
                ordered[2] - ordered[3]
            )

            width_b = np.linalg.norm(
                ordered[1] - ordered[0]
            )

            max_width = max(
                int(width_a),
                int(width_b)
            )


            # Tính chiều cao

            height_a = np.linalg.norm(
                ordered[1] - ordered[2]
            )

            height_b = np.linalg.norm(
                ordered[0] - ordered[3]
            )

            max_height = max(
                int(height_a),
                int(height_b)
            )


            if (
                max_width < 5
                or max_height < 5
            ):

                continue


            # ------------------------------------------------
            # Nếu box bị dọc thì xoay lại
            # ------------------------------------------------

            destination = np.array(
                [
                    [0, 0],
                    [max_width - 1, 0],
                    [max_width - 1, max_height - 1],
                    [0, max_height - 1]
                ],
                dtype=np.float32
            )


            matrix = cv2.getPerspectiveTransform(
                ordered,
                destination
            )


            crop = cv2.warpPerspective(
                recognition_image,
                matrix,
                (
                    max_width,
                    max_height
                ),
                flags=cv2.INTER_CUBIC,
                borderMode=cv2.BORDER_CONSTANT,
                borderValue=(
                    255,
                    255,
                    255
                )
            )


        except Exception as exc:

            if DEBUG_FILTER:

                print(
                    f"[FILTER] Loi crop box {i}: "
                    f"{exc}"
                )

            continue


        # ====================================================
        # 5. Nếu chữ bị dựng dọc thì xoay 90 độ
        # ====================================================

        crop_h, crop_w = crop.shape[:2]

        if crop_h > crop_w * 1.5:

            crop = cv2.rotate(
                crop,
                cv2.ROTATE_90_CLOCKWISE
            )


        if crop.size == 0:

            continue


        # ====================================================
        # 6. Padding
        # ====================================================

        crop = cv2.copyMakeBorder(
            crop,
            8,
            8,
            8,
            8,
            cv2.BORDER_CONSTANT,
            value=(
                255,
                255,
                255
            )
        )


        # ====================================================
        # 7. Resize nếu crop quá nhỏ
        # ====================================================

        crop_h, crop_w = crop.shape[:2]

        if crop_h < 32:

            scale = 32 / crop_h

            crop = cv2.resize(
                crop,
                (
                    int(crop_w * scale),
                    32
                ),
                interpolation=cv2.INTER_CUBIC
            )


        # ====================================================
        # 8. NumPy RGB -> PIL
        #
        # preprocess() của Vy tạo ảnh RGB.
        # Vì vậy KHÔNG dùng COLOR_BGR2RGB nữa.
        # ====================================================

        crop_pil = Image.fromarray(
            crop
        )


        # ====================================================
        # 9. VietOCR
        # ====================================================

        try:

            with vietocr_lock:

                text = vietocr_engine.predict(
                    crop_pil
                )


        except Exception as exc:

            print(
                f"[VietOCR] Loi crop "
                f"{i + 1}: {exc}"
            )

            continue


        # ====================================================
        # 10. Kiểm tra kết quả
        # ====================================================

        if (
            not text
            or not text.strip()
        ):

            if DEBUG_FILTER:

                print(
                    f"[FILTER] Box {i} "
                    f"VietOCR tra ve rong "
                    f"| x={x_min}-{x_max}, "
                    f"y={y_min}-{y_max}"
                )

            continue


        # ====================================================
        # 11. Lưu kết quả
        # ====================================================

        lines.append(
            {
                "text": text.strip(),
                "confidence": 1.0,
                "box": box_np.tolist(),
            }
        )


    return lines

# ============================================================
# HEALTH
# ============================================================

@app.get("/health")
def health():

    return {
        "status": "ok",
        "ocr": "PaddleOCR + VietOCR",
    }


# ============================================================
# XỬ LÝ OCR CHO 1 ẢNH (SYNC, CHẠY TRONG THREADPOOL)
#
# THÊM MỚI: tách toàn bộ phần xử lý nặng (preprocess + PaddleOCR +
# VietOCR) ra một hàm sync riêng để chạy qua run_in_executor.
# Nhờ vậy route async không còn bị block hoàn toàn event loop nữa —
# nhiều request có thể cùng "đang xử lý" song song ở bước preprocess,
# chỉ xếp hàng đúng lúc chạm vào model (nhờ ocr_lock / vietocr_lock).
#
# LƯU Ý: endpoint /ocr (1 ảnh đơn - thường là CCCD chụp trực tiếp, không
# phải PDF gộp nhiều giấy tờ) KHÔNG áp dụng cơ chế skip theo document_type
# — vì với ảnh đơn không có chuyện "trang khác không cần dùng" như PDF
# nhiều trang, luôn luôn cần đọc toàn bộ nội dung ảnh đã upload.
# ============================================================

def _process_ocr_image(contents: bytes, filename: str) -> dict:

    t_start = time.time()

    # ========================================================
    # 1. MỞ ẢNH
    # ========================================================

    try:

        pil_image = Image.open(
            io.BytesIO(contents)
        )

    except Exception as exc:

        raise HTTPException(
            status_code=400,
            detail=f"Anh khong hop le: {exc}",
        )

    print(
        f"[TIME] Doc + mo anh: "
        f"{time.time() - t_start:.2f}s "
        f"| size goc: {pil_image.size}"
    )

    # ========================================================
    # 2. PREPROCESS
    #
    # recognition_image:
    #     Ảnh giữ độ phân giải tốt để crop đưa vào VietOCR.
    #
    # detect_image:
    #     Ảnh 960x960 cố định chỉ dùng cho PaddleOCR detect.
    # ========================================================

    t1 = time.time()

    recognition_image = preprocess(
        pil_image
    )

    print(
        f"[TIME] Preprocess: "
        f"{time.time() - t1:.2f}s "
        f"| size recognition: "
        f"{recognition_image.shape[1]}x"
        f"{recognition_image.shape[0]}"
    )

    # ========================================================
    # 3. TẠO ẢNH RIÊNG CHO DETECTION
    #
    # resize_and_pad() cần trả về:
    #
    # detect_image
    # det_scale
    # new_w
    # new_h
    # ========================================================

    detect_image, det_scale, _, _ = resize_and_pad(
        recognition_image
    )

    print(
        f"[INFO] Detect image: "
        f"{detect_image.shape[1]}x"
        f"{detect_image.shape[0]}"
        f" | scale={det_scale:.4f}"
    )

    # ========================================================
    # 4. LƯU DEBUG IMAGE
    # ========================================================

    if SAVE_DEBUG_IMAGES:

        try:

            os.makedirs(
                "debug_images",
                exist_ok=True
            )

            debug_name = os.path.splitext(
                filename or "unknown"
            )[0]

            # Ảnh gốc
            pil_image.save(
                f"debug_images/"
                f"{debug_name}_1_original.jpg"
            )

            # Ảnh recognition
            Image.fromarray(
                recognition_image
            ).save(
                f"debug_images/"
                f"{debug_name}_2_recognition.jpg"
            )

            # Ảnh detect 960x960
            Image.fromarray(
                detect_image
            ).save(
                f"debug_images/"
                f"{debug_name}_3_detection.jpg"
            )

        except Exception as exc:

            print(
                f"[DEBUG] Khong luu duoc "
                f"anh debug: {exc}"
            )

    # ========================================================
    # 5. PADDLEOCR DETECTION
    # ========================================================

    print(
        f"Dang OCR anh: {filename}"
    )

    t2 = time.time()

    try:

        with ocr_lock:

            results = list(
                ocr_engine.predict(
                    detect_image
                )
            )

    except Exception as exc:

        raise HTTPException(
            status_code=500,
            detail=f"PaddleOCR loi: {exc}",
        )

    print(
        f"[TIME] PaddleOCR detect: "
        f"{time.time() - t2:.2f}s"
    )

    # ========================================================
    # 6. VIETOCR
    #
    # Quan trọng:
    #
    # detect_image
    #     -> dùng để PaddleOCR tìm box
    #
    # recognition_image
    #     -> dùng để crop ảnh chất lượng cao cho VietOCR
    #
    # det_scale
    #     -> scale box từ detect_image về recognition_image
    # ========================================================

    t3 = time.time()

    lines = []

    for res in results:

        page_lines = extract_lines(

            res=res,

            detect_image=detect_image,

            recognition_image=recognition_image,

            det_scale=det_scale,

        )

        lines.extend(
            page_lines
        )

    print(
        f"[TIME] VietOCR "
        f"({len(lines)} vung chu): "
        f"{time.time() - t3:.2f}s"
    )

    # ========================================================
    # 7. SORT THEO VỊ TRÍ
    # ========================================================

    lines.sort(

        key=lambda l: (

            round(
                l["box"][0][1] / 10
            ),

            l["box"][0][0],

        )

    )

    # ========================================================
    # 8. GHÉP RAW TEXT
    # ========================================================

    raw_text = "\n".join(

        line["text"]

        for line in lines

        if line.get("text")

    )

    # ========================================================
    # 9. LOG KẾT QUẢ
    # ========================================================

    print()

    print("=" * 70)

    print("KET QUA OCR")

    print("=" * 70)

    for index, line in enumerate(
        lines,
        start=1
    ):

        print(
            f"[{index:03d}] "
            f"{line['text']}"
        )

    print("=" * 70)

    print(
        f"[TIME] TONG CONG: "
        f"{time.time() - t_start:.2f}s"
    )

    print("=" * 70)

    # ========================================================
    # 10. RESPONSE
    # ========================================================

    return {

        "raw_text": raw_text,

        "lines": lines,

    }


# ============================================================
# OCR IMAGE
# ============================================================

@app.post("/ocr")
async def run_ocr(
    image: UploadFile = File(...)
):

    contents = await image.read()
    filename = image.filename

    # THÊM MỚI: đẩy phần xử lý blocking ra threadpool riêng,
    # event loop không còn bị treo trong lúc OCR chạy.
    loop = asyncio.get_event_loop()

    try:
        result = await loop.run_in_executor(
            executor,
            _process_ocr_image,
            contents,
            filename,
        )
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(
            status_code=500,
            detail=f"Loi xu ly OCR: {exc}",
        )

    return result


# ============================================================
# XỬ LÝ OCR CHO PDF
#
# THÊM MỚI: nhận thêm document_type để biết CHỈ cần lấy loại giấy tờ
# nào trong PDF nhiều trang/nhiều loại giấy tờ gộp chung, từ đó SKIP
# VietOCR (bước chậm) cho các trang không thuộc loại cần dùng.
# ============================================================

def _process_ocr_pdf(
    contents: bytes,
    document_type: str | None = None
) -> dict:

    # ========================================================
    # XÁC ĐỊNH LOẠI TRANG CẦN GIỮ
    # ========================================================

    needed_types = NEEDED_TYPES_BY_DOCTYPE.get(
        document_type
    )

    print(
        f"[INFO] document_type nhan duoc: "
        f"{document_type!r} "
        f"| needed_types: "
        f"{needed_types if needed_types else 'KHONG RO - xu ly tat ca trang'}"
    )

    # ========================================================
    # MỞ PDF
    # ========================================================

    try:

        doc = fitz.open(
            stream=contents,
            filetype="pdf"
        )

    except Exception as exc:

        raise HTTPException(
            status_code=400,
            detail=f"PDF khong hop le: {exc}",
        )

    pages = []

    try:

        # ====================================================
        # DUYỆT TỪNG TRANG
        # ====================================================

        for page_number, page in enumerate(
            doc,
            start=1
        ):

            t_page = time.time()

            print(
                f"Dang OCR PDF trang {page_number}..."
            )

            # =================================================
            # 1. RENDER PDF -> IMAGE
            # =================================================

            pix = page.get_pixmap(
                matrix=fitz.Matrix(3, 3)
            )

            img_bytes = pix.tobytes(
                "png"
            )

            pil_image = Image.open(
                io.BytesIO(img_bytes)
            )

            # =================================================
            # 2. PREPROCESS
            #
            # recognition_image:
            # giữ chất lượng tốt để crop cho VietOCR
            # =================================================

            recognition_image = preprocess(
                pil_image
            )

            # =================================================
            # 3. TẠO detect_image 960x960
            # =================================================

            (
                detect_image,
                det_scale,
                _,
                _
            ) = resize_and_pad(
                recognition_image
            )

            print(
                f"[INFO] Trang {page_number} "
                f"| recognition="
                f"{recognition_image.shape[1]}x"
                f"{recognition_image.shape[0]} "
                f"| detect="
                f"{detect_image.shape[1]}x"
                f"{detect_image.shape[0]} "
                f"| scale={det_scale:.4f}"
            )

            # =================================================
            # 4. PADDLEOCR
            #
            # Luôn detect trên ảnh 960x960
            # =================================================

            try:

                with ocr_lock:

                    results = list(
                        ocr_engine.predict(
                            detect_image
                        )
                    )

            except Exception as exc:

                raise HTTPException(
                    status_code=500,
                    detail=(
                        f"PaddleOCR loi o trang "
                        f"{page_number}: {exc}"
                    ),
                )

            # =================================================
            # 5. PHÂN LOẠI NHANH TRANG
            # =================================================

            quick_text = " ".join(

                get_quick_text(res)

                for res in results

            )

            page_type = classify_page_quick(
                quick_text
            )

            print(
                f"[CLASSIFY] Trang {page_number}: "
                f"loai='{page_type}' "
                f"(quick_text preview: "
                f"{quick_text[:80]!r})"
            )

            # =================================================
            # 6. QUYẾT ĐỊNH CÓ CHẠY VIETOCR KHÔNG
            # =================================================

            should_run_vietocr = (

                needed_types is None

                or page_type in needed_types

            )

            # =================================================
            # 7. VIETOCR
            # =================================================

            page_lines = []

            if should_run_vietocr:

                print(
                    f"[VIETOCR] Trang {page_number}: "
                    f"bat dau OCR"
                )

                for res in results:

                    lines = extract_lines(

                        res=res,

                        detect_image=detect_image,

                        recognition_image=recognition_image,

                        det_scale=det_scale,

                    )

                    page_lines.extend(
                        item["text"]

                        for item in lines

                        if item.get("text")
                    )

            else:

                print(
                    f"[SKIP] Trang {page_number}: "
                    f"bo qua VietOCR "
                    f"(loai='{page_type}' "
                    f"khong thuoc nhu cau "
                    f"cua document_type="
                    f"'{document_type}')"
                )

            # =================================================
            # 8. GHÉP TEXT CỦA TRANG
            # =================================================

            page_text = "\n".join(
                page_lines
            ).strip()

            pages.append({

                "page": page_number,

                "text": page_text,

                "type": page_type,

                "vietocr_ran": should_run_vietocr,

            })

            print(
                f"[TIME] Trang {page_number}: "
                f"{time.time() - t_page:.2f}s"
            )

    finally:

        doc.close()

    # ========================================================
    # GỘP RAW TEXT
    # ========================================================

    final_raw_text = "\n\n".join(

        page["text"]

        for page in pages

        if page["text"]

    )

    # ========================================================
    # LOG
    # ========================================================

    print()

    print("=" * 70)

    print("KET QUA OCR PDF")

    print("=" * 70)

    for page in pages:

        print()

        print(
            f"---------------- TRANG "
            f"{page['page']} "
            f"(loai: {page['type']}, "
            f"vietocr_ran: "
            f"{page['vietocr_ran']}) "
            f"----------------"
        )

        print(
            page["text"]
            or "(bo qua - khong chay VietOCR)"
        )

    print()

    print("=" * 70)

    print("KET THUC OCR PDF")

    print("=" * 70)

    # ========================================================
    # RESPONSE
    # ========================================================

    return {

        "raw_text": final_raw_text,

        "pages": pages,

        "page_count": len(pages),

    }


# ============================================================
# OCR PDF
#
# THÊM MỚI: nhận thêm field document_type (form-data) từ Laravel, để
# biết CHỈ cần OCR chính xác (VietOCR) cho trang nào, bỏ qua các trang
# thuộc loại giấy tờ khác không liên quan.
# ============================================================

@app.post("/ocr-pdf")
async def run_ocr_pdf(
    file: UploadFile = File(...),
    document_type: str | None = Form(None),
):

    contents = await file.read()

    loop = asyncio.get_event_loop()

    try:
        result = await loop.run_in_executor(
            executor,
            _process_ocr_pdf,
            contents,
            document_type,
        )
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(
            status_code=500,
            detail=f"Loi xu ly OCR PDF: {exc}",
        )

    return result
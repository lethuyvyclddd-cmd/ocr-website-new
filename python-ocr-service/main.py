"""
Vietnamese OCR microservice
PaddleOCR 3.x: phát hiện vị trí chữ
VietOCR: nhận dạng nội dung từng vùng chữ

Đã sửa để tối ưu tốc độ chạy trên CPU:
 1. enable_mkldnn=True -> bật tăng tốc CPU của PaddleOCR
 2. preprocess(): giới hạn CẢ hai chiều (không chỉ phóng to ảnh nhỏ,
    mà còn thu nhỏ ảnh quá lớn trước khi xử lý)
 3. Bỏ cv2.fastNlMeansDenoisingColored (rất chậm trên CPU), thay bằng
    bilateralFilter nhẹ hơn nhiều (có thể tắt hẳn nếu vẫn chậm)
 4. Thêm timing log ở từng bước để dễ xác định chỗ nghẽn
"""

import os

os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

import io
import time

import cv2
import numpy as np

from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, ImageOps, ImageEnhance

import pymupdf as fitz  # PyMuPDF

from paddleocr import PaddleOCR
from vietocr.tool.predictor import Predictor
from vietocr.tool.config import Cfg


# ============================================================
# CONFIG
# ============================================================

# Kích thước cạnh dài nhất mong muốn sau khi resize.
# Ảnh quá lớn (>MAX_SIDE) sẽ bị thu nhỏ, ảnh quá nhỏ (<MIN_SIDE) sẽ
# được phóng to. Đây là điểm quan trọng nhất ảnh hưởng tốc độ CPU.
MAX_SIDE = 1600
MIN_SIDE = 1500

# Kích thước canvas vuông CỐ ĐỊNH mà MỌI ảnh sẽ được đệm (pad) vào trước khi
# đưa vào PaddleOCR detect. Phải >= MAX_SIDE để đảm bảo ảnh sau resize luôn
# vừa khít. Xem giải thích chi tiết trong preprocess().
FIXED_CANVAS = 1600

# Bật/tắt bước khử nhiễu (bilateralFilter). Nếu vẫn thấy chậm, hoặc
# ảnh đầu vào đã khá sạch, có thể set False để bỏ hẳn bước này.
ENABLE_DENOISE = True

# Lưu ảnh gốc + ảnh sau preprocess vào thư mục debug_images/ để kiểm
# tra bằng mắt. Nên BẬT trong lúc debug, tắt khi lên production (tránh
# đầy ổ đĩa).
SAVE_DEBUG_IMAGES = True


# ============================================================
# FASTAPI
# ============================================================

app = FastAPI(
    title="Vietnamese OCR Service"
)


# ============================================================
# PADDLEOCR
# ============================================================

print("=" * 70)
print("DANG KHOI TAO PADDLEOCR...")
print("=" * 70)

# GHI CHÚ QUAN TRỌNG (sau khi thử nghiệm thực tế):
# Trước đây đã thử đổi sang class TextDetection (chỉ detect, không rec)
# để tăng tốc, với giả thuyết rằng bước nhận dạng thừa của Paddle là
# nguyên nhân chính gây chậm. Thực tế: TextDetection KHÔNG nhanh hơn đáng
# kể trên máy này (vẫn 100-130s, so với ~200s của pipeline đầy đủ), NHƯNG
# lại làm hỏng chất lượng gộp box chữ - chữ bị vỡ vụn thành từng từ rời
# rạc thay vì gộp đúng theo dòng, sai cả thứ tự đọc.
#
# => Quay lại dùng PaddleOCR (pipeline đầy đủ) để giữ đúng chất lượng gộp
# dòng chữ đã được kiểm chứng tốt. Chấp nhận chậm hơn (nhưng vẫn nằm
# trong giới hạn timeout 240s của Laravel).
ocr_engine = PaddleOCR(
    lang="vi",
    use_textline_orientation=True,
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    enable_mkldnn=False,  # tránh bug PIR/oneDNN của PaddlePaddle 3.3.x
                          # (NotImplementedError: ConvertPirAttribute2RuntimeAttribute...)
                          # Fix triệt để: downgrade `pip install paddlepaddle==3.2.2`
)

print("PaddleOCR khoi tao OK.")

# --------------------------------------------------------
# WARM-UP: lần predict() ĐẦU TIÊN của Paddle thường phải biên dịch/tối ưu
# hóa graph, tốn rất nhiều thời gian (quan sát thực tế: ~200s). Nếu không
# warm-up, chi phí này sẽ rơi vào request THẬT ĐẦU TIÊN của người dùng và
# gây timeout. Chạy thử 1 ảnh giả ngay lúc khởi động để trả chi phí này
# trước, các request sau sẽ nhanh hơn nhiều.
# --------------------------------------------------------

print("Dang warm-up PaddleOCR (co the mat 1-3 phut, chi chay 1 lan)...")

_warmup_t0 = time.time()
_dummy_img = np.full((FIXED_CANVAS, FIXED_CANVAS, 3), 255, dtype=np.uint8)

try:
    _ = list(ocr_engine.predict(_dummy_img))
    print(f"Warm-up PaddleOCR xong: {time.time() - _warmup_t0:.2f}s")
except Exception as _exc:
    print(f"Warm-up PaddleOCR loi (bo qua, se thu lai o request dau): {_exc}")


# ============================================================
# VIETOCR
# ============================================================

print("=" * 70)
print("DANG KHOI TAO VIETOCR...")
print("=" * 70)

# Ghi chú: "vgg_transformer" chính xác nhất nhưng chậm nhất trên CPU.
# Nếu sau khi tối ưu ảnh mà vẫn chậm (đặc biệt khi ảnh có nhiều vùng
# chữ), đổi sang "vgg_seq2seq" để tăng tốc, đánh đổi một chút độ chính xác.
VIETOCR_MODEL_NAME = "vgg_transformer"
# VIETOCR_MODEL_NAME = "vgg_seq2seq"

vietocr_config = Cfg.load_config_from_name(VIETOCR_MODEL_NAME)
vietocr_config["device"] = "cpu"

vietocr_engine = Predictor(vietocr_config)

print(f"VietOCR ({VIETOCR_MODEL_NAME}) khoi tao OK.")

# --------------------------------------------------------
# WARM-UP VietOCR (chi phi thuong nho hon Paddle, nhung van warm-up
# cho chac, tranh request dau cua nguoi dung bi cong them do tre).
# --------------------------------------------------------

try:
    _dummy_pil = Image.new("RGB", (100, 32), (255, 255, 255))
    _ = vietocr_engine.predict(_dummy_pil)
    print("Warm-up VietOCR xong.")
except Exception as _exc:
    print(f"Warm-up VietOCR loi (bo qua): {_exc}")

print("=" * 70)


# ============================================================
# PREPROCESS IMAGE
# ============================================================

def preprocess(image: Image.Image) -> np.ndarray:

    image = ImageOps.exif_transpose(image)
    image = image.convert("RGB")

    # --------------------------------------------------------
    # Cắt bớt viền
    # --------------------------------------------------------

    w, h = image.size

    margin_w = int(w * 0.08)
    margin_h = int(h * 0.08)

    image = image.crop(
        (
            margin_w,
            margin_h,
            w - margin_w,
            h - margin_h,
        )
    )

    # --------------------------------------------------------
    # Chuẩn hóa kích thước: thu nhỏ nếu quá lớn, phóng to nếu quá nhỏ.
    # ĐÂY LÀ FIX QUAN TRỌNG NHẤT - bản gốc chỉ có nhánh phóng to,
    # khiến ảnh chụp điện thoại (3000-4000px) đi thẳng vào bước khử
    # nhiễu + PaddleOCR detect ở full-size, rất chậm trên CPU.
    # --------------------------------------------------------

    w, h = image.size
    longest_side = max(w, h)
    upscale_factor = 1.0

    if longest_side > MAX_SIDE:
        scale = MAX_SIDE / longest_side
        image = image.resize(
            (int(w * scale), int(h * scale)),
            Image.LANCZOS,
        )
    elif longest_side < MIN_SIDE:
        scale = MIN_SIDE / longest_side
        upscale_factor = scale
        image = image.resize(
            (int(w * scale), int(h * scale)),
            Image.LANCZOS,
        )

    # --------------------------------------------------------
    # Tăng contrast + sharpness
    #
    # QUAN TRỌNG: nếu ảnh gốc nhỏ và bị phóng to nhiều (upscale_factor
    # lớn), sharpen mạnh sẽ KHUẾCH ĐẠI nhiễu/artifact do phóng to tạo
    # ra, có thể phá vỡ chữ thật thành các mảnh vô nghĩa (quan sát thực
    # tế: ảnh 720x834 bị phóng lên 1294x1500 + sharpen 1.5 -> PaddleOCR
    # detect ra toàn rác). Giảm sharpen tỉ lệ nghịch với mức phóng to.
    # --------------------------------------------------------

    contrast_amount = 1.3

    if upscale_factor > 1.3:
        # Ảnh bị phóng to nhiều -> gần như tắt sharpen, giữ contrast nhẹ
        sharpness_amount = 1.05
        contrast_amount = 1.15
    elif upscale_factor > 1.0:
        sharpness_amount = 1.2
    else:
        sharpness_amount = 1.5

    image = ImageEnhance.Contrast(image).enhance(contrast_amount)
    image = ImageEnhance.Sharpness(image).enhance(sharpness_amount)

    # --------------------------------------------------------
    # Chuyển sang numpy
    # --------------------------------------------------------

    img_array = np.array(image)

    # --------------------------------------------------------
    # Khử nhiễu (nhẹ hơn nhiều so với fastNlMeansDenoisingColored,
    # vốn rất chậm trên CPU với ảnh lớn - có thể mất hàng chục giây
    # đến vài phút chỉ riêng bước đó).
    # --------------------------------------------------------

    if ENABLE_DENOISE:
        img_array = cv2.bilateralFilter(img_array, d=5, sigmaColor=50, sigmaSpace=50)

    # --------------------------------------------------------
    # ÉP VỀ CANVAS VUÔNG KÍCH THƯỚC CỐ ĐỊNH (FIXED_CANVAS x FIXED_CANVAS)
    #
    # QUAN TRỌNG - đây là fix cho vấn đề tốc độ KHÔNG ỔN ĐỊNH quan sát
    # được: cùng máy, cùng warm-up, nhưng có ảnh detect chỉ mất ~15s, có
    # ảnh mất tới 130-200s. Nguyên nhân nghi ngờ: PaddleOCR/PaddleX có thể
    # biên dịch lại đồ thị tính toán (graph) RIÊNG cho từng KÍCH THƯỚC ảnh
    # đầu vào khác nhau ("shape-specific"). Vì bước resize ở trên chỉ ép
    # CẠNH DÀI NHẤT về khoảng MIN_SIDE-MAX_SIDE, cạnh còn lại vẫn thay đổi
    # tuỳ tỉ lệ khung hình gốc của từng ảnh -> mỗi ảnh có kích thước mới lại
    # phải "biên dịch lại từ đầu", dù đã warm-up trước đó với 1 kích thước
    # khác (ảnh warm-up cố định 640x640 cũng không khớp bất kỳ kích thước
    # ảnh thật nào).
    #
    # Giải pháp: đệm (pad) ảnh vào giữa 1 canvas nền trắng có kích thước
    # CỐ ĐỊNH DUY NHẤT, để MỌI request đều dùng chung 1 "shape". Khi đó chỉ
    # cần biên dịch 1 lần lúc warm-up (nếu ảnh warm-up cũng dùng đúng kích
    # thước này), các request sau tái sử dụng, không phải biên dịch lại.
    # --------------------------------------------------------

    canvas = np.full((FIXED_CANVAS, FIXED_CANVAS, 3), 255, dtype=np.uint8)

    h, w = img_array.shape[:2]

    # Nếu ảnh (sau resize) vẫn lớn hơn canvas ở 1 chiều nào đó (hiếm khi xảy
    # ra vì MAX_SIDE=1600 > FIXED_CANVAS, nhưng phòng hờ), thu nhỏ lại cho
    # vừa canvas trước khi dán vào.
    if h > FIXED_CANVAS or w > FIXED_CANVAS:
        scale = min(FIXED_CANVAS / h, FIXED_CANVAS / w)
        new_w, new_h = int(w * scale), int(h * scale)
        img_array = cv2.resize(img_array, (new_w, new_h), interpolation=cv2.INTER_AREA)
        h, w = new_h, new_w

    offset_y = (FIXED_CANVAS - h) // 2
    offset_x = (FIXED_CANVAS - w) // 2
    canvas[offset_y:offset_y + h, offset_x:offset_x + w] = img_array

    return canvas


# ============================================================
# HELPER LẤY DATA TỪ PADDLEOCR
# ============================================================

def _get(res, key, default=None):
    """
    Truy cập an toàn cả dict lẫn object PaddleX.
    """

    try:
        value = res[key]
        return value if value is not None else default
    except (KeyError, TypeError, IndexError):
        return default


# ============================================================
# EXTRACT + VIETOCR
# ============================================================

def extract_lines(res, image: np.ndarray, min_score: float = 0.6, min_box_area: int = 150) -> list[dict]:
    """
    PaddleOCR:
        - chỉ dùng để phát hiện vùng chữ.

    VietOCR:
        - nhận dạng nội dung từng vùng chữ.

    min_score:
        - bỏ qua vùng có độ tin cậy phát hiện thấp (thường là hoa văn,
          quốc huy, con dấu, viền trang trí bị nhận nhầm là chữ - hay
          gặp trên bằng tốt nghiệp / giấy tờ có nền phức tạp).

    min_box_area:
        - bỏ qua vùng quá nhỏ (px^2), thường là nhiễu, không phải chữ thật.

    Kết quả:
        [
            {
                "text": "...",
                "confidence": ...,
                "box": [...]
            }
        ]
    """

    # --------------------------------------------------------
    # PaddleOCR 3.x trả polygon ở rec_polys
    # --------------------------------------------------------

    polys = _get(res, "rec_polys")

    # --------------------------------------------------------
    # Fallback
    # --------------------------------------------------------

    if polys is None:
        polys = _get(res, "dt_polys")

    if polys is None:
        polys = []

    # --------------------------------------------------------
    # Điểm tin cậy từng box (TextDetection trả về "dt_scores")
    # --------------------------------------------------------

    scores = _get(res, "dt_scores", None)

    lines = []

    # ========================================================
    # DUYỆT TỪNG VÙNG CHỮ
    # ========================================================

    for i, poly in enumerate(polys):

        # ----------------------------------------------------
        # Lọc theo độ tin cậy (bỏ hoa văn/con dấu/quốc huy bị nhận nhầm)
        # ----------------------------------------------------

        if scores is not None and i < len(scores) and scores[i] < min_score:
            continue

        # ----------------------------------------------------
        # Chuyển polygon thành list
        # ----------------------------------------------------

        if hasattr(poly, "tolist"):
            box = poly.tolist()
        else:
            box = poly

        if not box or len(box) < 4:
            continue

        # ----------------------------------------------------
        # Chuyển sang numpy
        # ----------------------------------------------------

        box_np = np.array(box, dtype=np.int32)

        # ----------------------------------------------------
        # Tọa độ vùng chữ
        # ----------------------------------------------------

        x_min = max(0, int(np.min(box_np[:, 0])))
        y_min = max(0, int(np.min(box_np[:, 1])))
        x_max = min(image.shape[1], int(np.max(box_np[:, 0])))
        y_max = min(image.shape[0], int(np.max(box_np[:, 1])))

        # ----------------------------------------------------
        # Box không hợp lệ hoặc quá nhỏ (nhiễu)
        # ----------------------------------------------------

        if x_max <= x_min or y_max <= y_min:
            continue

        if (x_max - x_min) * (y_max - y_min) < min_box_area:
            continue

        # ----------------------------------------------------
        # Crop
        # ----------------------------------------------------

        crop = image[y_min:y_max, x_min:x_max]

        if crop.size == 0:
            continue

        # ----------------------------------------------------
        # Padding
        # ----------------------------------------------------

        crop = cv2.copyMakeBorder(
            crop, 5, 5, 5, 5,
            cv2.BORDER_CONSTANT,
            value=(255, 255, 255),
        )

        # ----------------------------------------------------
        # BGR -> RGB
        # ----------------------------------------------------

        crop_rgb = cv2.cvtColor(crop, cv2.COLOR_BGR2RGB)
        crop_pil = Image.fromarray(crop_rgb)

        # ====================================================
        # VIETOCR NHẬN DẠNG
        # ====================================================

        try:
            text = vietocr_engine.predict(crop_pil)
        except Exception as exc:
            print(f"[VietOCR] Loi crop {i + 1}: {exc}")
            continue

        if not text or not text.strip():
            continue

        # ----------------------------------------------------
        # Thêm kết quả
        # ----------------------------------------------------

        lines.append(
            {
                "text": text.strip(),
                "confidence": 1.0,
                "box": box,
            }
        )

    return lines


# ============================================================
# HEALTH CHECK
# ============================================================

@app.get("/health")
def health():
    return {
        "status": "ok",
        "ocr": "PaddleOCR + VietOCR"
    }


# ============================================================
# OCR IMAGE
# ============================================================

@app.post("/ocr")
async def run_ocr(image: UploadFile = File(...)):

    t_start = time.time()

    # --------------------------------------------------------
    # Đọc file
    # --------------------------------------------------------

    contents = await image.read()

    # --------------------------------------------------------
    # Mở ảnh
    # --------------------------------------------------------

    try:
        pil_image = Image.open(io.BytesIO(contents))
    except Exception as exc:
        raise HTTPException(
            status_code=400,
            detail=f"Anh khong hop le: {exc}",
        )

    print(f"[TIME] Doc + mo anh: {time.time() - t_start:.2f}s | size goc: {pil_image.size}")

    # --------------------------------------------------------
    # Preprocess
    # --------------------------------------------------------

    t1 = time.time()
    img_array = preprocess(pil_image)
    print(f"[TIME] Preprocess: {time.time() - t1:.2f}s | size sau: {img_array.shape[1]}x{img_array.shape[0]}")

    # --------------------------------------------------------
    # DEBUG: lưu ảnh gốc và ảnh sau preprocess ra đĩa để kiểm tra bằng
    # mắt xem ảnh đưa vào OCR có bị hỏng/nhiễu do preprocess không.
    # Xem trong thư mục debug_images/ cùng cấp với main.py.
    # Có thể tắt bằng cách set SAVE_DEBUG_IMAGES = False ở đầu file.
    # --------------------------------------------------------

    if SAVE_DEBUG_IMAGES:
        try:
            os.makedirs("debug_images", exist_ok=True)
            debug_name = os.path.splitext(image.filename or "unknown")[0]
            pil_image.save(f"debug_images/{debug_name}_1_goc.jpg")
            Image.fromarray(img_array).save(f"debug_images/{debug_name}_2_sau_preprocess.jpg")
        except Exception as _exc:
            print(f"[DEBUG] Khong luu duoc anh debug: {_exc}")

    # ========================================================
    # PADDLEOCR DETECT
    # ========================================================

    print(f"Dang OCR anh: {image.filename}")

    t2 = time.time()
    try:
        results = ocr_engine.predict(img_array)
    except Exception as exc:
        raise HTTPException(
            status_code=500,
            detail=f"PaddleOCR loi: {exc}",
        )
    print(f"[TIME] PaddleOCR detect: {time.time() - t2:.2f}s")

    # ========================================================
    # VIETOCR
    # ========================================================

    t3 = time.time()
    lines = []

    for res in results:
        page_lines = extract_lines(res, img_array)
        lines.extend(page_lines)

    print(f"[TIME] VietOCR ({len(lines)} vung chu): {time.time() - t3:.2f}s")

    # ========================================================
    # SẮP XẾP THEO VỊ TRÍ
    # ========================================================

    lines.sort(
        key=lambda l: (
            round(l["box"][0][1] / 10),
            l["box"][0][0],
        )
    )

    # ========================================================
    # RAW TEXT
    # ========================================================

    raw_text = "\n".join(l["text"] for l in lines)

    print()
    print("=" * 70)
    print("KET QUA OCR")
    print("=" * 70)

    for index, line in enumerate(lines, start=1):
        print(f"[{index:03d}] {line['text']}")

    print("=" * 70)
    print(f"[TIME] TONG CONG: {time.time() - t_start:.2f}s")
    print("=" * 70)

    # ========================================================
    # RESPONSE
    # ========================================================

    return {
        "raw_text": raw_text,
        "lines": lines,
    }


# ============================================================
# OCR PDF
# ============================================================

@app.post("/ocr-pdf")
async def run_ocr_pdf(file: UploadFile = File(...)):

    # --------------------------------------------------------
    # Đọc PDF
    # --------------------------------------------------------

    contents = await file.read()

    # --------------------------------------------------------
    # Mở PDF
    # --------------------------------------------------------

    try:
        doc = fitz.open(stream=contents, filetype="pdf")
    except Exception as exc:
        raise HTTPException(
            status_code=400,
            detail=f"PDF khong hop le: {exc}",
        )

    all_pages_text = []

    # ========================================================
    # DUYỆT TỪNG TRANG
    # ========================================================

    for page_number, page in enumerate(doc, start=1):

        t_page = time.time()
        print(f"Dang OCR PDF trang {page_number}...")

        # ----------------------------------------------------
        # PDF -> ảnh
        # ----------------------------------------------------

        pix = page.get_pixmap(matrix=fitz.Matrix(2, 2))
        img_bytes = pix.tobytes("png")
        pil_image = Image.open(io.BytesIO(img_bytes))

        # ----------------------------------------------------
        # Preprocess
        # ----------------------------------------------------

        img_array = preprocess(pil_image)

        # ----------------------------------------------------
        # PaddleOCR
        # ----------------------------------------------------

        try:
            results = ocr_engine.predict(img_array)
        except Exception as exc:
            raise HTTPException(
                status_code=500,
                detail=f"PaddleOCR loi o trang {page_number}: {exc}",
            )

        # ----------------------------------------------------
        # VietOCR
        # ----------------------------------------------------

        page_lines = []

        for res in results:
            lines = extract_lines(res, img_array)
            page_lines.extend(l["text"] for l in lines)

        # ----------------------------------------------------
        # Ghép text trang
        # ----------------------------------------------------

        all_pages_text.append("\n".join(page_lines))

        print(f"[TIME] Trang {page_number}: {time.time() - t_page:.2f}s")

    # ========================================================
    # ĐÓNG PDF
    # ========================================================

    doc.close()

    # ========================================================
    # IN RA TEXT OCR THO (giong endpoint /ocr, de tien debug khi
    # upload PDF nhu phieu dang ky, bang diem...)
    # ========================================================

    final_raw_text = "\n\n".join(all_pages_text)

    print()
    print("=" * 70)
    print("KET QUA OCR (PDF)")
    print("=" * 70)
    print(final_raw_text)
    print("=" * 70)

    # ========================================================
    # RESPONSE
    # ========================================================

    return {
        "raw_text": final_raw_text
    }
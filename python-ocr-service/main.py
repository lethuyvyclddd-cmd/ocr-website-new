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
"""

import os

# Tránh lỗi OpenMP trên Windows
os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

import io
import time

import cv2
import numpy as np

from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, ImageOps, ImageEnhance

import pymupdf as fitz

from paddleocr import PaddleOCR

from vietocr.tool.predictor import Predictor
from vietocr.tool.config import Cfg


# ============================================================
# CẤU HÌNH
# ============================================================

# Ảnh quá lớn sẽ được thu nhỏ xuống tối đa khoảng 1000px
MAX_SIDE = 1000

# Không dùng upscale ảnh nhỏ
ENABLE_DENOISE = False

# Tắt lưu ảnh debug để giảm I/O
SAVE_DEBUG_IMAGES = False


# ============================================================
# FASTAPI
# ============================================================

app = FastAPI(
    title="Vietnamese OCR Service"
)


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

    # Bật tăng tốc CPU
    enable_mkldnn=True,

    # Giảm kích thước xử lý detection
    text_det_limit_side_len=960,
)

print("PaddleOCR khoi tao OK.")


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

    _ = list(
        ocr_engine.predict(_dummy_img)
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


# ============================================================
# WARM-UP VIETOCR
# ============================================================

try:

    _dummy_pil = Image.new(
        "RGB",
        (100, 32),
        (255, 255, 255),
    )

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
# PADDLEOCR DETECTION
# +
# VIETOCR RECOGNITION
# ============================================================

def extract_lines(
    res,
    image: np.ndarray,
    min_score: float = 0.6,
    min_box_area: int = 150
) -> list[dict]:

    """
    PaddleOCR:
        Chỉ phát hiện vùng chữ.

    VietOCR:
        Nhận dạng nội dung từng vùng chữ.
    """

    # --------------------------------------------------------
    # Lấy polygon
    # --------------------------------------------------------

    polys = _get(
        res,
        "rec_polys"
    )

    if polys is None:

        polys = _get(
            res,
            "dt_polys"
        )

    if polys is None:

        polys = []


    # --------------------------------------------------------
    # Score
    # --------------------------------------------------------

    scores = _get(
        res,
        "dt_scores",
        None
    )


    lines = []


    # ========================================================
    # DUYỆT CÁC VÙNG CHỮ
    # ========================================================

    for i, poly in enumerate(polys):

        # ----------------------------------------------------
        # Lọc confidence
        # ----------------------------------------------------

        if (
            scores is not None
            and i < len(scores)
            and scores[i] < min_score
        ):

            continue


        # ----------------------------------------------------
        # Polygon -> list
        # ----------------------------------------------------

        if hasattr(poly, "tolist"):

            box = poly.tolist()

        else:

            box = poly


        if not box or len(box) < 4:

            continue


        # ----------------------------------------------------
        # Tạo bounding box
        # ----------------------------------------------------

        box_np = np.array(
            box,
            dtype=np.int32
        )

        x_min = max(
            0,
            int(np.min(box_np[:, 0]))
        )

        y_min = max(
            0,
            int(np.min(box_np[:, 1]))
        )

        x_max = min(
            image.shape[1],
            int(np.max(box_np[:, 0]))
        )

        y_max = min(
            image.shape[0],
            int(np.max(box_np[:, 1]))
        )


        if (
            x_max <= x_min
            or y_max <= y_min
        ):

            continue


        # ----------------------------------------------------
        # Bỏ vùng quá nhỏ
        # ----------------------------------------------------

        area = (
            x_max - x_min
        ) * (
            y_max - y_min
        )

        if area < min_box_area:

            continue


        # ----------------------------------------------------
        # Crop
        # ----------------------------------------------------

        crop = image[
            y_min:y_max,
            x_min:x_max
        ]


        if crop.size == 0:

            continue


        # ----------------------------------------------------
        # Padding
        # ----------------------------------------------------

        crop = cv2.copyMakeBorder(
            crop,
            5,
            5,
            5,
            5,
            cv2.BORDER_CONSTANT,
            value=(255, 255, 255),
        )


        # ----------------------------------------------------
        # BGR -> RGB
        # ----------------------------------------------------

        crop_rgb = cv2.cvtColor(
            crop,
            cv2.COLOR_BGR2RGB
        )


        crop_pil = Image.fromarray(
            crop_rgb
        )


        # ----------------------------------------------------
        # VietOCR
        # ----------------------------------------------------

        try:

            text = vietocr_engine.predict(
                crop_pil
            )

        except Exception as exc:

            print(
                f"[VietOCR] Loi crop "
                f"{i + 1}: {exc}"
            )

            continue


        if (
            not text
            or not text.strip()
        ):

            continue


        # ----------------------------------------------------
        # Lưu kết quả
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
# HEALTH
# ============================================================

@app.get("/health")
def health():

    return {
        "status": "ok",
        "ocr": "PaddleOCR + VietOCR",
    }


# ============================================================
# OCR IMAGE
# ============================================================

@app.post("/ocr")
async def run_ocr(
    image: UploadFile = File(...)
):

    t_start = time.time()


    # ========================================================
    # ĐỌC FILE
    # ========================================================

    contents = await image.read()


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
    # PREPROCESS
    # ========================================================

    t1 = time.time()

    img_array = preprocess(
        pil_image
    )

    print(
        f"[TIME] Preprocess: "
        f"{time.time() - t1:.2f}s "
        f"| size sau: "
        f"{img_array.shape[1]}x"
        f"{img_array.shape[0]}"
    )


    # ========================================================
    # DEBUG IMAGE
    # ========================================================

    if SAVE_DEBUG_IMAGES:

        try:

            os.makedirs(
                "debug_images",
                exist_ok=True
            )

            debug_name = os.path.splitext(
                image.filename or "unknown"
            )[0]

            pil_image.save(
                f"debug_images/"
                f"{debug_name}_1_goc.jpg"
            )

            Image.fromarray(
                img_array
            ).save(
                f"debug_images/"
                f"{debug_name}_2_sau_preprocess.jpg"
            )

        except Exception as _exc:

            print(
                "[DEBUG] Khong luu duoc "
                f"anh debug: {_exc}"
            )


    print(
        f"Dang OCR anh: "
        f"{image.filename}"
    )


    # ========================================================
    # PADDLEOCR
    # ========================================================

    t2 = time.time()


    try:

        results = ocr_engine.predict(
            img_array
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
    # VIETOCR
    # ========================================================

    t3 = time.time()

    lines = []


    for res in results:

        page_lines = extract_lines(
            res,
            img_array
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
    # SORT THEO VỊ TRÍ
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
    # RAW TEXT
    # ========================================================

    raw_text = "\n".join(
        l["text"]
        for l in lines
    )


    # ========================================================
    # PRINT RESULT
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
async def run_ocr_pdf(
    file: UploadFile = File(...)
):

    contents = await file.read()


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


    all_pages_text = []


    # ========================================================
    # DUYỆT TỪNG TRANG
    # ========================================================

    for page_number, page in enumerate(
        doc,
        start=1
    ):

        t_page = time.time()


        print(
            f"Dang OCR PDF "
            f"trang {page_number}..."
        )


        # ----------------------------------------------------
        # Render PDF
        # ----------------------------------------------------

        pix = page.get_pixmap(
            matrix=fitz.Matrix(2, 2)
        )


        img_bytes = pix.tobytes(
            "png"
        )


        pil_image = Image.open(
            io.BytesIO(img_bytes)
        )


        # ----------------------------------------------------
        # Preprocess
        # ----------------------------------------------------

        img_array = preprocess(
            pil_image
        )


        # ----------------------------------------------------
        # PaddleOCR
        # ----------------------------------------------------

        try:

            results = ocr_engine.predict(
                img_array
            )

        except Exception as exc:

            raise HTTPException(
                status_code=500,
                detail=(
                    f"PaddleOCR loi "
                    f"o trang {page_number}: "
                    f"{exc}"
                ),
            )


        # ----------------------------------------------------
        # VietOCR
        # ----------------------------------------------------

        page_lines = []


        for res in results:

            lines = extract_lines(
                res,
                img_array
            )

            page_lines.extend(
                l["text"]
                for l in lines
            )


        all_pages_text.append(
            "\n".join(page_lines)
        )


        print(
            f"[TIME] Trang "
            f"{page_number}: "
            f"{time.time() - t_page:.2f}s"
        )


    # ========================================================
    # ĐÓNG PDF
    # ========================================================

    doc.close()


    final_raw_text = "\n\n".join(
        all_pages_text
    )


    # ========================================================
    # PRINT
    # ========================================================

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
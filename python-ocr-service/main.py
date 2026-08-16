"""
Vietnamese OCR microservice
PaddleOCR 3.x: phát hiện vị trí chữ
VietOCR: nhận dạng nội dung từng vùng chữ
"""

import os

os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

import io

import cv2
import numpy as np

from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, ImageOps

import pymupdf as fitz  # PyMuPDF

from paddleocr import PaddleOCR
from vietocr.tool.predictor import Predictor
from vietocr.tool.config import Cfg


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

ocr_engine = PaddleOCR(
    lang="vi",
    use_textline_orientation=True,
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    enable_mkldnn=False,
)

print("PaddleOCR khoi tao OK.")


# ============================================================
# VIETOCR
# ============================================================

print("=" * 70)
print("DANG KHOI TAO VIETOCR...")
print("=" * 70)

vietocr_config = Cfg.load_config_from_name(
    "vgg_transformer"
)

vietocr_config["device"] = "cpu"

vietocr_engine = Predictor(
    vietocr_config
)

print("VietOCR khoi tao OK.")

print("=" * 70)


# ============================================================
# PREPROCESS IMAGE
# ============================================================

def preprocess(image: Image.Image) -> np.ndarray:

    image = ImageOps.exif_transpose(
        image
    )

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
    # Phóng to ảnh nếu ảnh quá nhỏ
    # --------------------------------------------------------

    w, h = image.size

    if max(w, h) < 1500:

        scale = 1500 / max(w, h)

        image = image.resize(
            (
                int(w * scale),
                int(h * scale),
            ),
            Image.LANCZOS,
        )

    # --------------------------------------------------------
    # Tăng contrast + sharpness
    # --------------------------------------------------------

    from PIL import ImageEnhance

    image = ImageEnhance.Contrast(
        image
    ).enhance(1.3)

    image = ImageEnhance.Sharpness(
        image
    ).enhance(1.5)

    # --------------------------------------------------------
    # Chuyển sang numpy
    # --------------------------------------------------------

    img_array = np.array(
        image
    )

    # --------------------------------------------------------
    # Khử nhiễu
    # --------------------------------------------------------

    img_array = cv2.fastNlMeansDenoisingColored(
        img_array,
        None,
        5,
        5,
        7,
        21,
    )

    return img_array


# ============================================================
# HELPER LẤY DATA TỪ PADDLEOCR
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
        IndexError,
    ):

        return default


# ============================================================
# EXTRACT + VIETOCR
# ============================================================

def extract_lines(
    res,
    image: np.ndarray
) -> list[dict]:

    """
    PaddleOCR:
        - chỉ dùng để phát hiện vùng chữ.

    VietOCR:
        - nhận dạng nội dung từng vùng chữ.

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

    polys = _get(
        res,
        "rec_polys"
    )

    # --------------------------------------------------------
    # Fallback
    # --------------------------------------------------------

    if polys is None:

        polys = _get(
            res,
            "dt_polys",
            []
        ) or []

    lines = []

    # ========================================================
    # DUYỆT TỪNG VÙNG CHỮ
    # ========================================================

    for i, poly in enumerate(polys):

        # ----------------------------------------------------
        # Chuyển polygon thành list
        # ----------------------------------------------------

        if hasattr(
            poly,
            "tolist"
        ):

            box = poly.tolist()

        else:

            box = poly

        if (
            not box
            or len(box) < 4
        ):

            continue

        # ----------------------------------------------------
        # Chuyển sang numpy
        # ----------------------------------------------------

        box_np = np.array(
            box,
            dtype=np.int32
        )

        # ----------------------------------------------------
        # Tọa độ vùng chữ
        # ----------------------------------------------------

        x_min = max(
            0,
            int(
                np.min(
                    box_np[:, 0]
                )
            )
        )

        y_min = max(
            0,
            int(
                np.min(
                    box_np[:, 1]
                )
            )
        )

        x_max = min(
            image.shape[1],
            int(
                np.max(
                    box_np[:, 0]
                )
            )
        )

        y_max = min(
            image.shape[0],
            int(
                np.max(
                    box_np[:, 1]
                )
            )
        )

        # ----------------------------------------------------
        # Box không hợp lệ
        # ----------------------------------------------------

        if (
            x_max <= x_min
            or y_max <= y_min
        ):

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
            value=(
                255,
                255,
                255
            ),
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

        # ====================================================
        # VIETOCR NHẬN DẠNG
        # ====================================================

        try:

            text = vietocr_engine.predict(
                crop_pil
            )

        except Exception as exc:

            print(
                f"[VietOCR] Loi crop {i + 1}: {exc}"
            )

            continue

        if (
            not text
            or not text.strip()
        ):

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
async def run_ocr(
    image: UploadFile = File(...)
):

    # --------------------------------------------------------
    # Đọc file
    # --------------------------------------------------------

    contents = await image.read()

    # --------------------------------------------------------
    # Mở ảnh
    # --------------------------------------------------------

    try:

        pil_image = Image.open(
            io.BytesIO(contents)
        )

    except Exception as exc:

        raise HTTPException(
            status_code=400,
            detail=f"Anh khong hop le: {exc}",
        )

    # --------------------------------------------------------
    # Preprocess
    # --------------------------------------------------------

    img_array = preprocess(
        pil_image
    )

    # ========================================================
    # PADDLEOCR DETECT
    # ========================================================

    print(
        f"Dang OCR anh: {image.filename}"
    )

    try:

        results = ocr_engine.predict(
            img_array
        )

    except Exception as exc:

        raise HTTPException(
            status_code=500,
            detail=f"PaddleOCR loi: {exc}",
        )

    # ========================================================
    # VIETOCR
    # ========================================================

    lines = []

    for res in results:

        page_lines = extract_lines(
            res,
            img_array
        )

        lines.extend(
            page_lines
        )

    # ========================================================
    # SẮP XẾP THEO VỊ TRÍ
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

    # --------------------------------------------------------
    # Đọc PDF
    # --------------------------------------------------------

    contents = await file.read()

    # --------------------------------------------------------
    # Mở PDF
    # --------------------------------------------------------

    try:

        doc = fitz.open(
            stream=contents,
            filetype="pdf",
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

        print(
            f"Dang OCR PDF trang {page_number}..."
        )

        # ----------------------------------------------------
        # PDF -> ảnh
        # ----------------------------------------------------

        pix = page.get_pixmap(
            matrix=fitz.Matrix(
                2,
                2
            )
        )

        img_bytes = pix.tobytes(
            "png"
        )

        pil_image = Image.open(
            io.BytesIO(
                img_bytes
            )
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
                    f"o trang {page_number}: {exc}"
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

        # ----------------------------------------------------
        # Ghép text trang
        # ----------------------------------------------------

        all_pages_text.append(
            "\n".join(
                page_lines
            )
        )

    # ========================================================
    # ĐÓNG PDF
    # ========================================================

    doc.close()

    # ========================================================
    # RESPONSE
    # ========================================================

    return {
        "raw_text": "\n\n".join(
            all_pages_text
        )
    }
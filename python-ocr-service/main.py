"""
Vietnamese OCR microservice - dùng PaddleOCR 3.x (predict()) để vừa
tìm vị trí chữ vừa đọc chữ trong một bước duy nhất.
"""

import os

os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

import io

import numpy as np
from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, ImageOps
import pymupdf as fitz  # PyMuPDF

app = FastAPI(title="Vietnamese OCR Service")

from paddleocr import PaddleOCR  # noqa: E402


# PaddleOCR 3.x:
# use_angle_cls -> use_textline_orientation
# .ocr() -> .predict()
ocr_engine = PaddleOCR(
    lang="vi",
    use_textline_orientation=True,
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    enable_mkldnn=False,  # tránh lỗi PIR/oneDNN của PaddlePaddle 3.3.x trên CPU
)


def preprocess(image: Image.Image) -> np.ndarray:
    from PIL import ImageEnhance
    import cv2

    image = ImageOps.exif_transpose(image)
    image = image.convert("RGB")

    # Cắt bớt 8% viền xung quanh - giảm nhiễu từ hoạ tiết viền trang trí
    w, h = image.size
    margin_w, margin_h = int(w * 0.08), int(h * 0.08)

    image = image.crop(
        (
            margin_w,
            margin_h,
            w - margin_w,
            h - margin_h,
        )
    )

    w, h = image.size

    # Chi resize khi can: anh QUA NHO thi phong to len (giup OCR doc chu nho ro
    # hon), anh QUA LON thi thu nho bot (anh chup dien thoai thuong 3000-4000px
    # -> vua khong can thiet cho do chinh xac OCR, vua lam buoc khu nhieu ben
    # duoi cham hon rat nhieu vi chi phi ty le voi so pixel).
    target_min, target_max = 1500, 2200

    if max(w, h) < target_min:
        scale = target_min / max(w, h)
    elif max(w, h) > target_max:
        scale = target_max / max(w, h)
    else:
        scale = None

    if scale:
        image = image.resize(
            (
                int(w * scale),
                int(h * scale),
            ),
            Image.LANCZOS,
        )

    image = ImageEnhance.Contrast(image).enhance(1.3)
    image = ImageEnhance.Sharpness(image).enhance(1.5)

    img_array = np.array(image)

    # searchWindowSize giam tu 21 xuong 11: day la tham so anh huong toc do
    # NHIEU NHAT cua fastNlMeansDenoisingColored (chi phi ~ ty le binh phuong
    # voi gia tri nay) -> giam tu 21 xuong 11 giup nhanh hon dang ke, chat
    # luong khu nhieu giam nhe nhung van chap nhan duoc cho muc dich OCR.
    img_array = cv2.fastNlMeansDenoisingColored(
        img_array,
        None,
        5,
        5,
        7,
        11,
    )

    return img_array


def _get(res, key, default=None):
    """
    Truy cap an toan ca dict lan object PaddleX
    (co __getitem__ nhung khong co .get).
    """
    try:
        value = res[key]
        return value if value is not None else default
    except (KeyError, TypeError, IndexError):
        return default


def extract_lines(res) -> list[dict]:
    """
    Chuyen 1 ket qua predict() thanh list dong text + box + confidence.
    """

    texts = _get(res, "rec_texts", []) or []
    scores = _get(res, "rec_scores", []) or []

    polys = _get(res, "rec_polys")

    if polys is None:
        polys = _get(res, "dt_polys", []) or []

    lines = []

    for i, text in enumerate(texts):
        if not text:
            continue

        confidence = float(scores[i]) if i < len(scores) else 0.0

        if i < len(polys) and hasattr(polys[i], "tolist"):
            box = polys[i].tolist()
        elif i < len(polys):
            box = polys[i]
        else:
            box = [[0, 0]]

        lines.append(
            {
                "text": text,
                "confidence": confidence,
                "box": box,
            }
        )

    return lines


def sort_lines_reading_order(lines: list[dict], image_width: int) -> list[dict]:
    """
    Sap xep cac dong text theo thu tu doc tu nhien.

    Mac dinh sap theo (hang y, cot x) - phu hop voi tai lieu 1 cot binh
    thuong (CCCD, phieu dang ky...). Nhung voi anh dang SONG NGUYEN (bang
    tot nghiep gap doi kieu 2 nua canh nhau: nua trai la anh + so hieu +
    so vao so + chu ky, nua phai la noi dung chinh) thi cach sap nay se
    TRON LAN 2 cot lai voi nhau (dong cung do cao nhung khac cot bi xen
    ke), lam thu tu doc bi dao lon hoan toan.

    Neu phat hien 1 khoang trong LON theo truc x (kieu duong gap giua 2
    nua trang), tach lam 2 cot, doc het cot trai (tren -> duoi) roi moi
    den cot phai, thay vi tron lan theo hang ngang.
    """
    if not lines:
        return lines

    centers_x = sorted(
        (l["box"][0][0] + l["box"][1][0]) / 2 for l in lines
    )

    biggest_gap = 0
    gap_pos = None

    for a, b in zip(centers_x, centers_x[1:]):
        gap = b - a
        if gap > biggest_gap:
            biggest_gap = gap
            gap_pos = (a + b) / 2

    is_two_column = (
        gap_pos is not None
        and biggest_gap > image_width * 0.12
        and image_width * 0.3 < gap_pos < image_width * 0.7
    )

    if not is_two_column:
        return sorted(
            lines,
            key=lambda l: (round(l["box"][0][1] / 10), l["box"][0][0]),
        )

    left = [
        l for l in lines
        if (l["box"][0][0] + l["box"][1][0]) / 2 < gap_pos
    ]
    right = [
        l for l in lines
        if (l["box"][0][0] + l["box"][1][0]) / 2 >= gap_pos
    ]

    row_key = lambda l: (round(l["box"][0][1] / 10), l["box"][0][0])
    left.sort(key=row_key)
    right.sort(key=row_key)

    return left + right


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/ocr")
async def run_ocr(image: UploadFile = File(...)):
    contents = await image.read()

    try:
        pil_image = Image.open(io.BytesIO(contents))
    except Exception as exc:
        raise HTTPException(
            status_code=400,
            detail=f"Anh khong hop le: {exc}",
        )

    img_array = preprocess(pil_image)

    results = ocr_engine.predict(img_array)

    lines = []

    for res in results:
        lines.extend(extract_lines(res))

    lines = sort_lines_reading_order(lines, img_array.shape[1])

    raw_text = "\n".join(
        l["text"] for l in lines
    )

    return {
        "raw_text": raw_text,
        "lines": lines,
    }


@app.post("/ocr-pdf")
async def run_ocr_pdf(file: UploadFile = File(...)):
    contents = await file.read()

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

    for page in doc:
        pix = page.get_pixmap(
            matrix=fitz.Matrix(2, 2)
        )

        img_bytes = pix.tobytes("png")

        pil_image = Image.open(
            io.BytesIO(img_bytes)
        )

        img_array = preprocess(pil_image)

        results = ocr_engine.predict(img_array)

        page_lines = []

        for res in results:
            page_lines.extend(
                l["text"]
                for l in extract_lines(res)
            )

        all_pages_text.append(
            "\n".join(page_lines)
        )

    return {
        "raw_text": "\n\n".join(all_pages_text)
    }
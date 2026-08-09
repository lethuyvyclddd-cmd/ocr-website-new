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

# PaddleOCR 3.x: use_angle_cls -> use_textline_orientation, .ocr() -> .predict()
ocr_engine = PaddleOCR(
    lang="vi",
    use_textline_orientation=True,
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    enable_mkldnn=False,  # tránh lỗi PIR/oneDNN của PaddlePaddle 3.3.x trên CPU
)


def preprocess(image: Image.Image) -> np.ndarray:
    image = ImageOps.exif_transpose(image)
    image = image.convert("RGB")

    w, h = image.size
    if max(w, h) < 1500:
        scale = 1500 / max(w, h)
        image = image.resize((int(w * scale), int(h * scale)), Image.LANCZOS)

    return np.array(image)


def _get(res, key, default=None):
    """Truy cap an toan ca dict lan object PaddleX (co __getitem__ nhung khong co .get)."""
    try:
        value = res[key]
        return value if value is not None else default
    except (KeyError, TypeError, IndexError):
        return default


def extract_lines(res) -> list[dict]:
    """Chuyen 1 ket qua predict() thanh list dong text + box + confidence."""
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
        lines.append({"text": text, "confidence": confidence, "box": box})
    return lines


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/ocr")
async def run_ocr(image: UploadFile = File(...)):
    contents = await image.read()

    try:
        pil_image = Image.open(io.BytesIO(contents))
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Anh khong hop le: {exc}")

    img_array = preprocess(pil_image)

    results = ocr_engine.predict(img_array)

    lines = []
    for res in results:
        lines.extend(extract_lines(res))

    lines.sort(key=lambda l: (round(l["box"][0][1] / 10), l["box"][0][0]))

    raw_text = "\n".join(l["text"] for l in lines)

    return {"raw_text": raw_text, "lines": lines}


@app.post("/ocr-pdf")
async def run_ocr_pdf(file: UploadFile = File(...)):
    contents = await file.read()

    try:
        doc = fitz.open(stream=contents, filetype="pdf")
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"PDF khong hop le: {exc}")

    all_pages_text = []

    for page in doc:
        pix = page.get_pixmap(matrix=fitz.Matrix(2, 2))
        img_bytes = pix.tobytes("png")
        pil_image = Image.open(io.BytesIO(img_bytes))
        img_array = preprocess(pil_image)

        results = ocr_engine.predict(img_array)

        page_lines = []
        for res in results:
            page_lines.extend(l["text"] for l in extract_lines(res))

        all_pages_text.append("\n".join(page_lines))

    return {"raw_text": "\n\n".join(all_pages_text)}
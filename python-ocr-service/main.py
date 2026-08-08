"""
Vietnamese OCR microservice - dùng PaddleOCR (open-source, miễn phí).
Laravel gọi sang service này qua HTTP để OCR ảnh.

Chạy:
    uvicorn main:app --host 0.0.0.0 --port 8001
"""

import io

import numpy as np
from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, ImageOps
import fitz  # PyMuPDF

app = FastAPI(title="Vietnamese OCR Service")

# Khởi tạo 1 lần khi service start (lần đầu sẽ tự tải model, hơi lâu).
from paddleocr import PaddleOCR  # noqa: E402

ocr_engine = PaddleOCR(use_angle_cls=True, lang="vi", show_log=False)


def preprocess(image: Image.Image) -> np.ndarray:
    image = ImageOps.exif_transpose(image)
    image = image.convert("RGB")

    w, h = image.size
    if max(w, h) < 1500:
        scale = 1500 / max(w, h)
        image = image.resize((int(w * scale), int(h * scale)), Image.LANCZOS)

    return np.array(image)


@app.get("/health")
def health():
    return {"status": "ok"}


@app.post("/ocr")
async def run_ocr(image: UploadFile = File(...)):
    contents = await image.read()

    try:
        pil_image = Image.open(io.BytesIO(contents))
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Ảnh không hợp lệ: {exc}")

    img_array = preprocess(pil_image)

    result = ocr_engine.ocr(img_array, cls=True)

    lines = []
    for block in result or []:
        if not block:
            continue
        for detection in block:
            text = detection[1][0]
            confidence = float(detection[1][1])
            box = detection[0]
            lines.append({"text": text, "confidence": confidence, "box": box})

    lines.sort(key=lambda l: (round(l["box"][0][1] / 10), l["box"][0][0]))

    raw_text = "\n".join(l["text"] for l in lines)

    return {"raw_text": raw_text, "lines": lines}

@app.post("/ocr-pdf")
async def run_ocr_pdf(file: UploadFile = File(...)):
    contents = await file.read()

    try:
        doc = fitz.open(stream=contents, filetype="pdf")
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"PDF không hợp lệ: {exc}")

    all_pages_text = []

    for page in doc:
        pix = page.get_pixmap(matrix=fitz.Matrix(2, 2))
        img_bytes = pix.tobytes("png")
        pil_image = Image.open(io.BytesIO(img_bytes))
        img_array = preprocess(pil_image)

        result = ocr_engine.ocr(img_array, cls=True)

        page_lines = []
        for block in result or []:
            if not block:
                continue
            for detection in block:
                page_lines.append(detection[1][0])

        all_pages_text.append("\n".join(page_lines))

    return {"raw_text": "\n\n".join(all_pages_text)}
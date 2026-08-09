"""
Vietnamese OCR microservice - kết hợp PaddleOCR (tìm vị trí chữ)
+ VietOCR (đọc chữ tiếng Việt, chính xác dấu tốt hơn).
"""

import os
os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

import io

import numpy as np
from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, ImageOps

app = FastAPI(title="Vietnamese OCR Service")

from paddleocr import PaddleOCR  # noqa: E402
from vietocr.tool.config import Cfg  # noqa: E402
from vietocr.tool.predictor import Predictor  # noqa: E402

# PaddleOCR: chỉ dùng để TÌM VỊ TRÍ có chữ trong ảnh (detection)
detector = PaddleOCR(use_angle_cls=True, lang="vi", show_log=False)

# VietOCR: dùng để ĐỌC CHỮ trong từng vùng đã tìm được (recognition)
vietocr_config = Cfg.load_config_from_name('vgg_transformer')
vietocr_config['device'] = 'cpu'
vietocr_config['predictor']['beamsearch'] = False
recognizer = Predictor(vietocr_config)


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

    # Bước 0: Thử OCR nhanh ở 4 góc xoay (0°, 90°, 180°, 270°), chọn góc đọc được
    # NHIỀU CHỮ NHẤT -> tự động xoay ảnh về đúng chiều trước khi đọc kỹ.
    best_angle = 0
    best_count = -1
    for angle in [0, 90, 180, 270]:
        rotated = np.array(Image.fromarray(img_array).rotate(-angle, expand=True))
        quick_result = detector.ocr(rotated, det=True, rec=False, cls=False)
        boxes_found = len(quick_result[0]) if quick_result and quick_result[0] else 0
        if boxes_found > best_count:
            best_count = boxes_found
            best_angle = angle

    if best_angle != 0:
        img_array = np.array(Image.fromarray(img_array).rotate(-best_angle, expand=True))

    full_image = Image.fromarray(img_array)

    # Bước 1: PaddleOCR chỉ tìm vị trí (không đọc chữ) -> det=True, rec=False
    detection = detector.ocr(img_array, det=True, rec=False, cls=True)
    boxes = detection[0] if detection else []

    lines = []
    for box in boxes or []:
        xs = [p[0] for p in box]
        ys = [p[1] for p in box]
        x1, y1, x2, y2 = int(min(xs)), int(min(ys)), int(max(xs)), int(max(ys))

        # Bỏ qua vùng quá nhỏ (nhiễu)
        if x2 - x1 < 3 or y2 - y1 < 3:
            continue

        crop = full_image.crop((x1, y1, x2, y2))

        # Bước 2: VietOCR đọc chữ trong vùng đã crop
        try:
            text = recognizer.predict(crop)
        except Exception:
            text = ""

        if text.strip():
            lines.append({"text": text, "confidence": 1.0, "box": box})

    lines.sort(key=lambda l: (round(l["box"][0][1] / 10), l["box"][0][0]))

    raw_text = "\n".join(l["text"] for l in lines)

    return {"raw_text": raw_text, "lines": lines}
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

os.environ["KMP_DUPLICATE_LIB_OK"] = "TRUE"

import io
import time
import asyncio
import threading
import unicodedata
import re
from concurrent.futures import ThreadPoolExecutor

import cv2
import numpy as np

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from PIL import Image, ImageOps, ImageEnhance

import pymupdf as fitz

from paddleocr import PaddleOCR

from vietocr.tool.predictor import Predictor
from vietocr.tool.config import Cfg

MONTHS = {
    "january":"01","february":"02","march":"03",
    "april":"04","may":"05","june":"06",
    "july":"07","august":"08","september":"09",
    "october":"10","november":"11","december":"12"
}

def normalize_english_date(text):
    m = re.search(
        r'(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})',
        text,
        re.I
    )
    if not m:
        return text
    day = int(m.group(1))
    month = MONTHS[m.group(2).lower()]
    year = m.group(3)
    return f"{day:02d}/{month}/{year}"

MAX_SIDE = 1400
ENABLE_DENOISE = False
SAVE_DEBUG_IMAGES = False
MAX_WORKERS = 4
DEBUG_FILTER = True
DET_TARGET_SIZE = 960

FOOTER_ENABLE = True
FOOTER_FILENAME_KEYWORDS = ("diploma", "bang", "transcript")
FOOTER_Y_START_RATIO = 0.78
FOOTER_Y_END_RATIO = 0.95
FOOTER_UPSCALE = 4

FOOTER_VARIANTS = [
    {
        "name": "triet_hoc_phat_giao",
        "keywords": ("triet hoc phat giao", "buddhist philosophy"),
        "y_start": 0.80,
        "y_end": 0.92,
    },
    {
        "name": "default",
        "keywords": (),
        "y_start": FOOTER_Y_START_RATIO,
        "y_end": FOOTER_Y_END_RATIO,
    },
]


def pick_footer_ratios(existing_text: str) -> tuple[float, float]:
    t = strip_diacritics_vn(existing_text)
    for variant in FOOTER_VARIANTS:
        if variant["name"] == "default":
            continue
        if any(kw in t for kw in variant["keywords"]):
            if DEBUG_FILTER:
                print(
                    f"[FOOTER] Nhan dien bien the: {variant['name']} "
                    f"(y_start={variant['y_start']}, y_end={variant['y_end']})"
                )
            return variant["y_start"], variant["y_end"]
    default = FOOTER_VARIANTS[-1]
    return default["y_start"], default["y_end"]


FOOTER_ANCHOR_KEYWORDS = (
    "so hieu",
    "so hiew",
    "hieu bang",
    "serial",
    "so vao so",
    "vao so cap bang",
    "reg no",
    "reg.",
    "so vho",
)

FOOTER_SHAPE_DENYLIST = (
    "thich tri quang",
    "chu tich",
    "ubnd",
    "phuong",
    "chung thuc",
    "ban sao",
    "given",
    "seal",
    "hoa thuong",
    "hon thuong",
    "vien truong",
    "rector",
    "university",
    "buddhist",
    "ngay",
)

# ============================================================
# SỬA (FIX MỚI - tách layout NHIỀU CỘT ngay từ đầu, không chỉ ở
# footer): trước đây chỉ có vùng "Số hiệu/Số vào sổ" được tách theo x
# thật (find_footer_anchor_y trả về x-range), còn TOÀN BỘ pipeline
# chính (sort_lines_reading_order) vẫn coi cả trang là 1 cột duy nhất
# -> dòng cột phải (vd "Trần Minh Chí" ở cột "cho [tên]") bị gộp cùng
# hàng với dòng cột trái (vd "39/m" ở cột "Số vào sổ") chỉ vì cùng y,
# rồi bị nối liền nhau trong text, và ảnh gốc (không qua enhance) vẫn
# bị bước detect/crop coi 2 box này là 2 dòng riêng nhưng THỨ TỰ ĐỌC
# bị trộn lẫn hàng ngang -> khi 2 box cách xa theo x nhưng gần theo y,
# add "col split": nếu độ lệch x giữa các item trong 1 "hàng" quá lớn
# so với chiều rộng ảnh (vượt COLUMN_GAP_RATIO), coi đó là 2 CỘT khác
# nhau và đọc lần lượt CỘT TRÁI (toàn bộ, trên->dưới) trước rồi mới
# đọc CỘT PHẢI, thay vì trộn theo hàng ngang. Đây là fix tổng quát,
# không chỉ áp dụng cho vùng footer.
# ============================================================
COLUMN_GAP_RATIO = 0.12  # khoảng trống giữa 2 cụm coi là biên cột


def _line_bounds(line: dict):
    box = line.get("box") or []
    pts = [p for p in box if isinstance(p, (list, tuple)) and len(p) >= 2]
    if not pts:
        return None
    xs = [float(p[0]) for p in pts]
    ys = [float(p[1]) for p in pts]
    return min(xs), min(ys), max(xs), max(ys)


def detect_columns(lines: list[dict], image_width: float | None = None) -> list[tuple[float, float]]:
    """
    THÊM MỚI: phát hiện các CỘT (column) trong trang dựa trên khoảng
    trống ngang giữa các box đã OCR — không đoán mù số cột cố định.

    Ý tưởng: nếu vẽ tất cả box lên 1 trục x (chỉ x_min/x_max), phần lớn
    text 1 cột sẽ có các đoạn [x_min, x_max] chồng lấp hoặc liền nhau.
    Khi có 2 cột thật, sẽ xuất hiện 1 khoảng TRỐNG (gap) đủ rộng giữa
    2 khối nội dung mà không có box nào "lấp" vào — đây chính là biên
    cột. Yêu cầu gap >= COLUMN_GAP_RATIO * image_width để tránh chia
    cột chỉ vì khoảng cách bình thường giữa các từ/label trong 1 dòng.

    Trả về list các (x_start, x_end) theo thứ tự trái -> phải. Nếu chỉ
    có 1 cột (hoặc không đủ dữ liệu), trả về [(0.0, image_width)] hoặc
    tương đương "không chia cột" để nơi gọi giữ hành vi cũ.
    """

    intervals = []
    for line in lines:
        b = _line_bounds(line)
        if b is None:
            continue
        x1, _, x2, _ = b
        intervals.append((x1, x2))

    if not intervals:
        return []

    if image_width is None:
        image_width = max(x2 for _, x2 in intervals)

    if image_width <= 0:
        return [(0.0, image_width)]

    intervals.sort(key=lambda iv: iv[0])

    merged = [list(intervals[0])]
    for x1, x2 in intervals[1:]:
        last = merged[-1]
        if x1 <= last[1]:
            last[1] = max(last[1], x2)
        else:
            merged.append([x1, x2])

    if len(merged) < 2:
        return [(0.0, image_width)]

    # Chỉ tách cột nếu khoảng trống giữa 2 khối đủ lớn (thực sự là biên
    # cột, không phải khoảng cách chữ trong-cùng-1-dòng).
    min_gap = COLUMN_GAP_RATIO * image_width

    columns = [merged[0]]
    for seg in merged[1:]:
        gap = seg[0] - columns[-1][1]
        if gap >= min_gap:
            columns.append(seg)
        else:
            columns[-1][1] = max(columns[-1][1], seg[1])

    if len(columns) < 2:
        return [(0.0, image_width)]

    return [(c[0], c[1]) for c in columns]


def sort_lines_reading_order(lines: list[dict]) -> list[dict]:
    """Sắp xếp OCR theo từng hàng: trên -> dưới, trong cùng hàng trái -> phải.

    SỬA (FIX MỚI - đọc theo CỘT trước khi đọc theo hàng): nếu
    detect_columns() phát hiện trang có >= 2 cột thật (dựa trên
    khoảng trống ngang giữa các box), ta đọc TOÀN BỘ cột trái (theo
    đúng logic group-theo-hàng cũ, chỉ trong phạm vi box thuộc cột
    trái) trước, rồi mới đọc toàn bộ cột phải — thay vì trộn chung
    theo y như trước (nguyên nhân khiến "Trần Minh Chí" ở cột phải bị
    ghép dính vào "Số vào sổ 39/m" ở cột trái chỉ vì 2 box tình cờ
    cùng độ cao). Nếu chỉ có 1 cột (trường hợp phổ biến nhất), hành vi
    giữ NGUYÊN như code cũ.
    """

    def _group_rows(items):
        items = sorted(items, key=lambda item: item[5])
        rows = []
        for item in items:
            line, x1, y1, x2, y2, yc, h = item
            placed = False
            for row in rows:
                row_yc = row["yc_sum"] / len(row["items"])
                row_h = row["h_sum"] / len(row["items"])
                tolerance = max(8.0, 0.65 * max(h, row_h))
                if abs(yc - row_yc) <= tolerance:
                    row["items"].append(item)
                    row["yc_sum"] += yc
                    row["h_sum"] += h
                    placed = True
                    break
            if not placed:
                rows.append({"items": [item], "yc_sum": yc, "h_sum": h})
        rows.sort(key=lambda row: row["yc_sum"] / len(row["items"]))
        ordered = []
        for row in rows:
            row["items"].sort(key=lambda item: item[1])
            ordered.extend(item[0] for item in row["items"])
        return ordered

    prepared = []
    for line in lines:
        bounds = _line_bounds(line)
        if bounds is None:
            continue
        x1, y1, x2, y2 = bounds
        prepared.append((line, x1, y1, x2, y2, (y1 + y2) / 2, max(1.0, y2 - y1)))

    if not prepared:
        return []

    image_width = max(item[3] for item in prepared)
    columns = detect_columns(lines, image_width=image_width)

    if len(columns) < 2:
        return _group_rows(prepared)

    # Có >= 2 cột thật: gán mỗi item vào cột chứa tâm x của nó, rồi đọc
    # cột trái xong mới đọc cột phải.
    ordered = []
    for col_start, col_end in columns:
        col_items = [
            item for item in prepared
            if col_start <= (item[1] + item[3]) / 2 <= col_end
        ]
        ordered.extend(_group_rows(col_items))

    return ordered


def is_trusted_footer_value(text: str) -> bool:
    """Chỉ nhận giá trị có hình dạng đặc trưng của số hiệu/số vào sổ.

    SỬA (FIX MỚI): mở rộng pattern "số/chữ" (vd "39/H") để nhận cả
    dạng còn giữ dấu "/" gốc (không chỉ dạng đã bỏ hết ký tự không phải
    chữ-số), và nới thêm 1 biến thể where ký tự cuối có thể là 1 CHỮ
    CÁI ĐƠN LẺ dễ bị OCR nhầm hình dạng với số/ký tự khác (H/M/N...).
    Không đổi 2 pattern gốc (K0016939 dạng chữ+số, và số+chữ dạng cũ),
    chỉ nới thêm để KHÔNG loại oan giá trị đúng dạng NN/X có gạch chéo.
    """
    if not text:
        return False

    raw = text.strip()
    value = re.sub(r"[^A-Za-z0-9]", "", raw).upper()

    # Ví dụ: K0016939, AB123456
    if re.fullmatch(r"[A-Z]{1,4}\d{4,10}", value):
        return True

    # Ví dụ: 39/M -> 39M, 123/AB -> 123AB (dạng đã bỏ dấu "/")
    if re.fullmatch(r"\d{1,6}[A-Z]{1,4}", value):
        return True

    # THÊM MỚI: dạng còn giữ dấu "/" gốc, số/số + "/" + 1-4 chữ cái,
    # ví dụ "39/H", "123/AB" — chấp nhận thêm để không phụ thuộc vào
    # việc dấu "/" có bị OCR đọc mất hay không.
    if re.fullmatch(r"\d{1,6}/[A-Za-z]{1,4}", raw):
        return True

    return False


def trusted_footer_values(footer_lines: list[dict]) -> list[dict]:
    result = []
    seen = set()
    ordered_lines = sort_lines_reading_order(footer_lines)
    if not ordered_lines:
        ordered_lines = footer_lines
    for line in ordered_lines:
        text = (line.get("text") or "").strip()
        if not is_trusted_footer_value(text):
            continue
        key = re.sub(r"[^A-Za-z0-9]", "", text).upper()
        if key in seen:
            continue
        seen.add(key)
        result.append(line)
    return result


def find_footer_anchor_by_shape(
    lines: list[dict],
    image_height: int,
) -> tuple[float, float] | None:
    candidates = []
    for line in lines:
        text = line.get("text") or ""
        box = line.get("box") or []
        if not text or not box:
            continue
        ys = [p[1] for p in box if len(p) == 2]
        if not ys:
            continue
        y_top = min(ys)
        if (y_top / image_height) < 0.85:
            continue
        norm = strip_diacritics_vn(text)
        if any(bad in norm for bad in FOOTER_SHAPE_DENYLIST):
            continue
        if not any(ch.isdigit() for ch in text):
            continue
        candidates.append((y_top, max(ys)))

    if not candidates:
        return None

    y_top = min(c[0] for c in candidates)
    y_bottom = max(c[1] for c in candidates)

    margin_up = image_height * 0.015
    margin_down = image_height * 0.05

    y_start = max(0.0, (y_top - margin_up) / image_height)
    y_end = min(1.0, (y_bottom + margin_down) / image_height)

    return y_start, y_end


def find_footer_anchor_y(
    lines: list[dict],
    image_height: int,
    image_width: int,
) -> tuple[float, float, float, float] | None:
    best_y_top = None
    best_y_bottom = None
    best_box = None

    for line in lines:
        text = line.get("text") or ""
        box = line.get("box") or []
        if not text or not box:
            continue
        norm = strip_diacritics_vn(text)
        if not any(kw in norm for kw in FOOTER_ANCHOR_KEYWORDS):
            continue
        ys = [p[1] for p in box if len(p) == 2]
        if not ys:
            continue
        y_top = min(ys)
        y_bottom = max(ys)
        if best_y_top is None or y_top < best_y_top:
            best_y_top = y_top
            best_y_bottom = y_bottom
            best_box = box

    if best_y_top is not None:
        margin_up = image_height * 0.015
        margin_down = image_height * 0.07

        y_start = max(0.0, (best_y_top - margin_up) / image_height)
        y_end = min(1.0, (best_y_bottom + margin_down) / image_height)

        xs = [p[0] for p in best_box if len(p) == 2] if best_box else []

        if xs:
            x_min_box = min(xs)
            margin_left = image_width * 0.05
            margin_right = image_width * 0.55
            x_start = max(0.0, (x_min_box - margin_left) / image_width)
            x_end = min(1.0, (x_min_box + margin_right) / image_width)
        else:
            x_start, x_end = 0.0, 1.0

        return y_start, y_end, x_start, x_end

    shape_result = find_footer_anchor_by_shape(lines, image_height)

    if shape_result is not None:
        y_start, y_end = shape_result
        return y_start, y_end, 0.0, 1.0

    return None


NEEDED_TYPES_BY_DOCTYPE = {
    "cccd_front": {"cccd"},
    "cccd_back": {"cccd"},
    "admission_form": {"phieu_dang_ky"},
    "diploma_transcript": {"bang_dai_hoc", "bang_cao_dang", "bang_trung_cap"},
}

app = FastAPI(title="Vietnamese OCR Service")

executor = ThreadPoolExecutor(max_workers=MAX_WORKERS)

print("=" * 70)
print("DANG KHOI TAO PADDLEOCR...")
print("=" * 70)

ocr_engine = PaddleOCR(
    lang="vi",
    text_detection_model_name="PP-OCRv6_small_det",
    text_recognition_model_name="PP-OCRv6_tiny_rec",
    use_textline_orientation=False,
    use_doc_orientation_classify=False,
    use_doc_unwarping=False,
    enable_mkldnn=False,
    text_det_limit_side_len=DET_TARGET_SIZE,
    text_det_thresh=0.2,
    text_det_box_thresh=0.4,
)

print("PaddleOCR khoi tao OK.")

ocr_lock = threading.Lock()


def resize_and_pad(img_array: np.ndarray, target: int = DET_TARGET_SIZE):
    h, w = img_array.shape[:2]
    scale = target / max(h, w)
    new_w = max(1, int(w * scale))
    new_h = max(1, int(h * scale))
    resized = cv2.resize(
        img_array,
        (new_w, new_h),
        interpolation=(cv2.INTER_AREA if scale < 1 else cv2.INTER_LANCZOS4),
    )
    canvas = np.full((target, target, 3), 255, dtype=np.uint8)
    canvas[0:new_h, 0:new_w] = resized
    return canvas, scale, new_w, new_h


def scale_poly_to_original(poly, scale: float, original_width: int, original_height: int):
    poly_np = np.array(poly, dtype=np.float32)
    poly_np = poly_np / scale
    poly_np[:, 0] = np.clip(poly_np[:, 0], 0, original_width - 1)
    poly_np[:, 1] = np.clip(poly_np[:, 1], 0, original_height - 1)
    return poly_np


def crop_and_enhance_footer(
    recognition_image: np.ndarray,
    y_start_ratio: float = FOOTER_Y_START_RATIO,
    y_end_ratio: float = FOOTER_Y_END_RATIO,
    x_start_ratio: float = 0.0,
    x_end_ratio: float = 1.0,
    scale: int = FOOTER_UPSCALE,
):
    if recognition_image is None or recognition_image.size == 0:
        return None

    h, w = recognition_image.shape[:2]

    y1 = max(0, int(h * y_start_ratio))
    y2 = min(h, int(h * y_end_ratio))
    x1 = max(0, int(w * x_start_ratio))
    x2 = min(w, int(w * x_end_ratio))

    if y2 <= y1 or x2 <= x1:
        return None

    footer = recognition_image[y1:y2, x1:x2]

    if footer.size == 0:
        return None

    fh, fw = footer.shape[:2]

    if fh < 2 or fw < 2:
        return None

    upscaled = cv2.resize(
        footer,
        (fw * scale, fh * scale),
        interpolation=cv2.INTER_CUBIC,
    )

    try:
        r_ch, g_ch, b_ch = cv2.split(upscaled)
        gray = cv2.min(g_ch, b_ch)
    except Exception as exc:
        if DEBUG_FILTER:
            print(f"[FOOTER] Loi khi khu muc do (fallback ve gray thuong): {exc}")
        gray = cv2.cvtColor(upscaled, cv2.COLOR_RGB2GRAY)

    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)

    blurred = cv2.GaussianBlur(enhanced, (0, 0), sigmaX=3)
    sharpened = cv2.addWeighted(enhanced, 1.5, blurred, -0.5, 0)

    footer_rgb = cv2.cvtColor(sharpened, cv2.COLOR_GRAY2RGB)

    if DEBUG_FILTER:
        try:
            os.makedirs("debug_footer", exist_ok=True)
            Image.fromarray(gray).save("debug_footer/_last_footer_red_suppressed_gray.jpg")
        except Exception as exc:
            print(f"[DEBUG-FOOTER] Loi khi luu anh khu do: {exc}")

    return footer_rgb


print("Dang warm-up PaddleOCR (co the mat vai chuc giay lan dau, chi chay 1 lan)...")

_warmup_t0 = time.time()

try:
    _dummy_img = np.full((800, 800, 3), 255, dtype=np.uint8)
    _dummy_padded, _, _, _ = resize_and_pad(_dummy_img)
    with ocr_lock:
        _ = list(ocr_engine.predict(_dummy_padded))
    print(f"Warm-up PaddleOCR xong: {time.time() - _warmup_t0:.2f}s")
except Exception as _exc:
    print(f"Warm-up PaddleOCR loi (bo qua, se thu lai o request dau): {_exc}")

print("=" * 70)

print("DANG KHOI TAO VIETOCR...")
print("=" * 70)

VIETOCR_MODEL_NAME = "vgg_transformer"
vietocr_config = Cfg.load_config_from_name(VIETOCR_MODEL_NAME)

from vietocr.tool import config as vietocr_config_module

vietocr_config_module.url_config["base"] = (
    "https://raw.githubusercontent.com/pbcquoc/vietocr/master/config/base.yml"
)
vietocr_config_module.url_config[VIETOCR_MODEL_NAME] = (
    f"https://raw.githubusercontent.com/pbcquoc/vietocr/master/config/{VIETOCR_MODEL_NAME}.yml"
)

vietocr_config = Cfg.load_config_from_name(VIETOCR_MODEL_NAME)
vietocr_config["device"] = "cpu"

vietocr_engine = Predictor(vietocr_config)

print(f"VietOCR ({VIETOCR_MODEL_NAME}) khoi tao OK.")

vietocr_lock = threading.Lock()

try:
    _dummy_pil = Image.new("RGB", (100, 32), (255, 255, 255))
    with vietocr_lock:
        _ = vietocr_engine.predict(_dummy_pil)
    print("Warm-up VietOCR xong.")
except Exception as _exc:
    print(f"Warm-up VietOCR loi (bo qua): {_exc}")

print("=" * 70)


def preprocess(image: Image.Image) -> np.ndarray:
    image = ImageOps.exif_transpose(image)
    image = image.convert("RGB")

    w, h = image.size

    longest_side = max(w, h)

    if longest_side > MAX_SIDE:
        scale = MAX_SIDE / longest_side
        image = image.resize((int(w * scale), int(h * scale)), Image.LANCZOS)

    image = ImageEnhance.Contrast(image).enhance(1.15)
    image = ImageEnhance.Sharpness(image).enhance(1.15)

    img_array = np.array(image)

    if ENABLE_DENOISE:
        img_array = cv2.bilateralFilter(img_array, d=5, sigmaColor=50, sigmaSpace=50)

    return img_array


def _get(res, key, default=None):
    try:
        value = res[key]
        return value if value is not None else default
    except (KeyError, TypeError, IndexError):
        return default


def strip_diacritics_vn(text: str) -> str:
    text = text.lower()
    text = text.replace("đ", "d")
    text = unicodedata.normalize("NFD", text)
    text = "".join(ch for ch in text if unicodedata.category(ch) != "Mn")
    return text


def get_quick_text(res) -> str:
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
    t = strip_diacritics_vn(quick_text)

    if "can cuoc cong dan" in t or "citizen identity card" in t:
        return "cccd"

    if "phieu dang ky" in t and "tuyen" in t:
        return "phieu_dang_ky"

    if "trung hoc pho thong" in t and "bang" in t:
        return "khac"

    for keyword in ("bang diem", "ket qua hoc tap", "diem trung binh", "hoc ba"):
        if keyword in t:
            return "khac"

    if any(keyword in t for keyword in ("bang dai hoc", "bang cu nhan", "bang ky su")):
        return "bang_dai_hoc"

    if "bang cao dang" in t:
        return "bang_cao_dang"

    if (
        any(keyword in t for keyword in ("bang tot nghiep trung cap", "bang trung cap"))
        or ("diploma" in t and "trung cap" in t)
    ):
        return "bang_trung_cap"

    return "khac"


def extract_lines(
    res,
    detect_image: np.ndarray,
    recognition_image: np.ndarray,
    det_scale: float,
    min_score: float = 0.3,
    min_box_area: int = 100
) -> list[dict]:

    polys = _get(res, "dt_polys")
    if polys is None:
        polys = _get(res, "rec_polys")
    if polys is None:
        polys = []

    scores = _get(res, "dt_scores", None)

    lines = []

    if DEBUG_FILTER:
        print(f"[DEBUG] Tong so vung chu PaddleOCR phat hien: {len(polys)}")
        if scores is not None:
            try:
                print("[DEBUG] Scores:", [round(float(score), 2) for score in scores])
            except Exception:
                pass

    for i, poly in enumerate(polys):

        if scores is not None and i < len(scores):
            try:
                score = float(scores[i])
                if score < min_score:
                    if DEBUG_FILTER:
                        print(f"[FILTER] Box {i} bi loai do score thap: {score:.2f}")
                    continue
            except Exception:
                pass

        try:
            box_detect = np.array(poly, dtype=np.float32)
            box_np = scale_poly_to_original(
                box_detect, det_scale, recognition_image.shape[1], recognition_image.shape[0]
            )
        except Exception as exc:
            if DEBUG_FILTER:
                print(f"[FILTER] Loi scale box {i}: {exc}")
            continue

        if box_np.shape[0] < 4:
            if DEBUG_FILTER:
                print(f"[FILTER] Box {i} khong du 4 diem")
            continue

        x_min = max(0, int(np.min(box_np[:, 0])))
        y_min = max(0, int(np.min(box_np[:, 1])))
        x_max = min(recognition_image.shape[1], int(np.max(box_np[:, 0])))
        y_max = min(recognition_image.shape[0], int(np.max(box_np[:, 1])))

        width = x_max - x_min
        height = y_max - y_min

        if width <= 0 or height <= 0:
            if DEBUG_FILTER:
                print(f"[FILTER] Box {i} khong hop le | width={width}, height={height}")
            continue

        area = width * height

        if area < min_box_area:
            if DEBUG_FILTER:
                print(f"[FILTER] Box {i} qua nho ({area}px) | x={x_min}-{x_max}, y={y_min}-{y_max}")
            continue

        try:
            rect = cv2.minAreaRect(box_np.astype(np.float32))
            box = cv2.boxPoints(rect)
            box = box.astype(np.float32)

            ordered = np.zeros((4, 2), dtype=np.float32)
            s = box.sum(axis=1)
            ordered[0] = box[np.argmin(s)]
            ordered[2] = box[np.argmax(s)]
            diff = np.diff(box, axis=1)
            ordered[1] = box[np.argmin(diff)]
            ordered[3] = box[np.argmax(diff)]

            width_a = np.linalg.norm(ordered[2] - ordered[3])
            width_b = np.linalg.norm(ordered[1] - ordered[0])
            max_width = max(int(width_a), int(width_b))

            height_a = np.linalg.norm(ordered[1] - ordered[2])
            height_b = np.linalg.norm(ordered[0] - ordered[3])
            max_height = max(int(height_a), int(height_b))

            if max_width < 5 or max_height < 5:
                continue

            destination = np.array(
                [[0, 0], [max_width - 1, 0], [max_width - 1, max_height - 1], [0, max_height - 1]],
                dtype=np.float32
            )

            matrix = cv2.getPerspectiveTransform(ordered, destination)

            crop = cv2.warpPerspective(
                recognition_image,
                matrix,
                (max_width, max_height),
                flags=cv2.INTER_CUBIC,
                borderMode=cv2.BORDER_CONSTANT,
                borderValue=(255, 255, 255)
            )

        except Exception as exc:
            if DEBUG_FILTER:
                print(f"[FILTER] Loi crop box {i}: {exc}")
            continue

        crop_h, crop_w = crop.shape[:2]

        if crop_h > crop_w * 1.5:
            crop = cv2.rotate(crop, cv2.ROTATE_90_CLOCKWISE)

        if crop.size == 0:
            continue

        crop = cv2.copyMakeBorder(crop, 8, 8, 8, 8, cv2.BORDER_CONSTANT, value=(255, 255, 255))

        crop_h, crop_w = crop.shape[:2]

        if crop_h < 32:
            scale = 32 / crop_h
            crop = cv2.resize(crop, (int(crop_w * scale), 32), interpolation=cv2.INTER_CUBIC)

        crop_pil = Image.fromarray(crop)

        try:
            with vietocr_lock:
                text = vietocr_engine.predict(crop_pil)

            text = normalize_english_date(text)

            text = (
                text.replace("TRỨNG CẤP", "TRUNG CẤP")
                    .replace("TRUNG CÁP", "TRUNG CẤP")
                    .replace("BÀNG", "BẰNG")
                    .replace("Giái", "Giỏi")
                    .replace("Giải", "Giỏi")
            )

            m = re.search(r'(\d{3})/TC[./]?((?:19|20)\d{2})', text)
            if m:
                text = f"{m.group(1)}/TC/{m.group(2)}"

            if re.fullmatch(r"\d{1,2}\.\d{4}", text.strip()):
                continue

        except Exception as exc:
            print(f"[VietOCR] Loi crop {i + 1}: {exc}")
            continue

        if not text or not text.strip():
            if DEBUG_FILTER:
                print(f"[FILTER] Box {i} VietOCR tra ve rong | x={x_min}-{x_max}, y={y_min}-{y_max}")
            continue

        lines.append({
            "text": text.strip(),
            "confidence": 1.0,
            "box": box_np.tolist(),
        })

    lines = dedupe_lines(lines)
    return lines


@app.get("/health")
def health():
    return {"status": "ok", "ocr": "PaddleOCR + VietOCR"}


def _process_ocr_image(contents: bytes, filename: str) -> dict:

    t_start = time.time()

    try:
        pil_image = Image.open(io.BytesIO(contents))
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Anh khong hop le: {exc}")

    print(f"[TIME] Doc + mo anh: {time.time() - t_start:.2f}s | size goc: {pil_image.size}")

    t1 = time.time()
    recognition_image = preprocess(pil_image)
    print(
        f"[TIME] Preprocess: {time.time() - t1:.2f}s | size recognition: "
        f"{recognition_image.shape[1]}x{recognition_image.shape[0]}"
    )

    detect_image, det_scale, _, _ = resize_and_pad(recognition_image)

    print(
        f"[INFO] Detect image: {detect_image.shape[1]}x{detect_image.shape[0]} "
        f"| scale={det_scale:.4f}"
    )

    if SAVE_DEBUG_IMAGES:
        try:
            os.makedirs("debug_images", exist_ok=True)
            debug_name = os.path.splitext(filename or "unknown")[0]
            pil_image.save(f"debug_images/{debug_name}_1_original.jpg")
            Image.fromarray(recognition_image).save(f"debug_images/{debug_name}_2_recognition.jpg")
            Image.fromarray(detect_image).save(f"debug_images/{debug_name}_3_detection.jpg")
        except Exception as exc:
            print(f"[DEBUG] Khong luu duoc anh debug: {exc}")

    print(f"Dang OCR anh: {filename}")

    t2 = time.time()

    try:
        with ocr_lock:
            results = list(ocr_engine.predict(detect_image))
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"PaddleOCR loi: {exc}")

    print(f"[TIME] PaddleOCR detect: {time.time() - t2:.2f}s")

    t3 = time.time()

    lines = []

    for res in results:
        page_lines = extract_lines(
            res=res,
            detect_image=detect_image,
            recognition_image=recognition_image,
            det_scale=det_scale,
        )
        lines.extend(page_lines)

    print(f"[TIME] VietOCR ({len(lines)} vung chu): {time.time() - t3:.2f}s")

    # SỬA (FIX MỚI): sort_lines_reading_order giờ tự phát hiện layout
    # nhiều cột (xem detect_columns()) và đọc cột trái xong mới đọc
    # cột phải, thay vì luôn trộn theo hàng ngang như trước.
    lines = sort_lines_reading_order(lines)

    is_diploma_like = bool(filename) and any(
        kw in filename.lower() for kw in FOOTER_FILENAME_KEYWORDS
    )

    if FOOTER_ENABLE and is_diploma_like:

        t_footer = time.time()

        if DEBUG_FILTER:
            recog_h = recognition_image.shape[0]
            print("[DEBUG-Y] Toa do y (%) cua tung dong (recognition_image):")
            for line in lines:
                if line.get("text") and line.get("box"):
                    y_top_pct = line["box"][0][1] / recog_h
                    print(f"[DEBUG-Y]   y_top%={y_top_pct:.3f} | text={line['text']!r}")

        recog_h = recognition_image.shape[0]
        recog_w = recognition_image.shape[1]
        anchor_result = find_footer_anchor_y(lines, recog_h, recog_w)

        if anchor_result is not None:
            (footer_y_start, footer_y_end, footer_x_start, footer_x_end) = anchor_result
            if DEBUG_FILTER:
                print(
                    f"[FOOTER] Dung ANCHOR THAT (toa do box that): "
                    f"y_start={footer_y_start:.3f}, y_end={footer_y_end:.3f}, "
                    f"x_start={footer_x_start:.3f}, x_end={footer_x_end:.3f}"
                )
        else:
            existing_text = "\n".join(line["text"] for line in lines if line.get("text"))
            footer_y_start, footer_y_end = pick_footer_ratios(existing_text)
            footer_x_start, footer_x_end = 0.0, 1.0
            if DEBUG_FILTER:
                print(
                    "[FOOTER] Khong tim thay anchor that, fallback bien the: "
                    f"y_start={footer_y_start:.3f}, y_end={footer_y_end:.3f}, "
                    f"x_start={footer_x_start:.3f}, x_end={footer_x_end:.3f}"
                )

        if DEBUG_FILTER:
            try:
                os.makedirs("debug_footer", exist_ok=True)
                debug_name = os.path.splitext(filename or "unknown")[0]

                y1 = max(0, int(recog_h * footer_y_start))
                y2 = min(recog_h, int(recog_h * footer_y_end))
                x1 = max(0, int(recog_w * footer_x_start))
                x2 = min(recog_w, int(recog_w * footer_x_end))

                region_preview = recognition_image.copy()
                cv2.rectangle(region_preview, (x1, y1), (x2 - 1, y2), (255, 0, 0), 3)
                Image.fromarray(region_preview).save(f"debug_footer/{debug_name}_footer_region.jpg")
                print(
                    f"[DEBUG-FOOTER] Da luu vung crop (o do) vao "
                    f"debug_footer/{debug_name}_footer_region.jpg "
                    f"| y_start={footer_y_start} (y={y1}px) y_end={footer_y_end} (y={y2}px) "
                    f"x_start={footer_x_start} (x={x1}px) x_end={footer_x_end} (x={x2}px)"
                )
            except Exception as exc:
                print(f"[DEBUG-FOOTER] Loi khi luu anh debug: {exc}")

        try:
            footer_image = crop_and_enhance_footer(
                recognition_image,
                y_start_ratio=footer_y_start,
                y_end_ratio=footer_y_end,
                x_start_ratio=footer_x_start,
                x_end_ratio=footer_x_end,
            )
        except Exception as exc:
            footer_image = None
            print(f"[FOOTER] Loi khi crop/tang cuong (bo qua): {exc}")

        if DEBUG_FILTER and footer_image is not None:
            try:
                os.makedirs("debug_footer", exist_ok=True)
                debug_name = os.path.splitext(filename or "unknown")[0]
                Image.fromarray(footer_image).save(f"debug_footer/{debug_name}_footer_cropped.jpg")
            except Exception as exc:
                print(f"[DEBUG-FOOTER] Loi khi luu anh cropped: {exc}")

        if footer_image is not None:

            try:
                footer_detect_image, footer_det_scale, _, _ = resize_and_pad(footer_image)

                with ocr_lock:
                    footer_results = list(ocr_engine.predict(footer_detect_image))

                footer_lines = []

                for res in footer_results:
                    footer_lines.extend(
                        extract_lines(
                            res=res,
                            detect_image=footer_detect_image,
                            recognition_image=footer_image,
                            det_scale=footer_det_scale,
                        )
                    )

                if footer_lines:
                    print(
                        f"[FOOTER] Doc them {len(footer_lines)} dong tu vung da tang cuong: "
                        f"{[l['text'] for l in footer_lines]}"
                    )

                    trusted = trusted_footer_values(footer_lines)
                    if trusted:
                        existing = {
                            re.sub(r"[^A-Za-z0-9]", "", (x.get("text") or "")).upper()
                            for x in lines
                        }
                        for new_line in trusted:
                            key = re.sub(r"[^A-Za-z0-9]", "", new_line["text"]).upper()
                            if key not in existing:
                                lines.append(new_line)
                                existing.add(key)
                        print(f"[FOOTER] Chap nhan {len(trusted)} gia tri tin cay: {[l['text'] for l in trusted]}")
                    else:
                        print("[FOOTER] Bo qua ket qua footer vi khong co gia tri so hieu/so vao so dang tin cay")
                else:
                    print("[FOOTER] Khong doc duoc dong nao tu vung tang cuong")

            except Exception as exc:
                print(f"[FOOTER] Loi khi OCR vung tang cuong (bo qua): {exc}")

        print(f"[TIME] Footer enhance + OCR: {time.time() - t_footer:.2f}s")

    raw_text = "\n".join(line["text"] for line in lines if line.get("text"))

    print()
    print("=" * 70)
    print("KET QUA OCR")
    print("=" * 70)

    for index, line in enumerate(lines, start=1):
        print(f"[{index:03d}] {line['text']}")

    print("=" * 70)
    print(f"[TIME] TONG CONG: {time.time() - t_start:.2f}s")
    print("=" * 70)

    return {"raw_text": raw_text, "lines": lines}


@app.post("/ocr")
async def run_ocr(image: UploadFile = File(...)):

    contents = await image.read()
    filename = image.filename

    loop = asyncio.get_event_loop()

    try:
        result = await loop.run_in_executor(executor, _process_ocr_image, contents, filename)
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Loi xu ly OCR: {exc}")

    return result


def _process_ocr_pdf(contents: bytes, document_type: str | None = None) -> dict:

    needed_types = NEEDED_TYPES_BY_DOCTYPE.get(document_type)

    print(
        f"[INFO] document_type nhan duoc: {document_type!r} | needed_types: "
        f"{needed_types if needed_types else 'KHONG RO - xu ly tat ca trang'}"
    )

    try:
        doc = fitz.open(stream=contents, filetype="pdf")
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"PDF khong hop le: {exc}")

    pages = []

    try:

        for page_number, page in enumerate(doc, start=1):

            t_page = time.time()

            print(f"Dang OCR PDF trang {page_number}...")

            pix = page.get_pixmap(matrix=fitz.Matrix(3, 3))
            img_bytes = pix.tobytes("png")
            pil_image = Image.open(io.BytesIO(img_bytes))

            recognition_image = preprocess(pil_image)

            (detect_image, det_scale, _, _) = resize_and_pad(recognition_image)

            print(
                f"[INFO] Trang {page_number} | recognition="
                f"{recognition_image.shape[1]}x{recognition_image.shape[0]} "
                f"| detect={detect_image.shape[1]}x{detect_image.shape[0]} "
                f"| scale={det_scale:.4f}"
            )

            try:
                with ocr_lock:
                    results = list(ocr_engine.predict(detect_image))
            except Exception as exc:
                raise HTTPException(status_code=500, detail=f"PaddleOCR loi o trang {page_number}: {exc}")

            quick_text = " ".join(get_quick_text(res) for res in results)

            page_type = classify_page_quick(quick_text)

            print(f"[CLASSIFY] Trang {page_number}: loai='{page_type}' (quick_text preview: {quick_text[:80]!r})")

            should_run_vietocr = needed_types is None or page_type in needed_types

            page_lines = []
            page_line_dicts = []

            if should_run_vietocr:

                print(f"[VIETOCR] Trang {page_number}: bat dau OCR")

                for res in results:

                    lines = extract_lines(
                        res=res,
                        detect_image=detect_image,
                        recognition_image=recognition_image,
                        det_scale=det_scale,
                    )

                    page_line_dicts.extend(lines)

                    page_lines.extend(item["text"] for item in lines if item.get("text"))

                # SỬA (FIX MỚI): sort theo layout (tự phát hiện nhiều cột).
                page_line_dicts = sort_lines_reading_order(page_line_dicts)
                page_lines = [item["text"] for item in page_line_dicts if item.get("text")]

                if FOOTER_ENABLE and page_type.startswith("bang_"):

                    t_footer = time.time()

                    recog_h = recognition_image.shape[0]
                    recog_w = recognition_image.shape[1]
                    anchor_result = find_footer_anchor_y(page_line_dicts, recog_h, recog_w)

                    if anchor_result is not None:
                        (footer_y_start, footer_y_end, footer_x_start, footer_x_end) = anchor_result
                        print(
                            f"[FOOTER] Trang {page_number}: dung ANCHOR THAT | "
                            f"y_start={footer_y_start:.3f}, y_end={footer_y_end:.3f}, "
                            f"x_start={footer_x_start:.3f}, x_end={footer_x_end:.3f}"
                        )
                    else:
                        existing_text = "\n".join(page_lines)
                        footer_y_start, footer_y_end = pick_footer_ratios(existing_text)
                        footer_x_start, footer_x_end = 0.0, 1.0
                        print(
                            f"[FOOTER] Trang {page_number}: khong tim thay anchor that, "
                            f"fallback bien the | y_start={footer_y_start:.3f}, "
                            f"y_end={footer_y_end:.3f}, x_start={footer_x_start:.3f}, "
                            f"x_end={footer_x_end:.3f}"
                        )

                    try:
                        footer_image = crop_and_enhance_footer(
                            recognition_image,
                            y_start_ratio=footer_y_start,
                            y_end_ratio=footer_y_end,
                            x_start_ratio=footer_x_start,
                            x_end_ratio=footer_x_end,
                        )
                    except Exception as exc:
                        footer_image = None
                        print(f"[FOOTER] Trang {page_number}: loi crop/tang cuong (bo qua): {exc}")

                    if footer_image is not None:

                        try:
                            (footer_detect_image, footer_det_scale, _, _) = resize_and_pad(footer_image)

                            with ocr_lock:
                                footer_results = list(ocr_engine.predict(footer_detect_image))

                            footer_texts = []

                            for res in footer_results:
                                footer_texts.extend(
                                    item["text"]
                                    for item in extract_lines(
                                        res=res,
                                        detect_image=footer_detect_image,
                                        recognition_image=footer_image,
                                        det_scale=footer_det_scale,
                                    )
                                    if item.get("text")
                                )

                            if footer_texts:
                                print(
                                    f"[FOOTER] Trang {page_number}: doc them {len(footer_texts)} dong: {footer_texts}"
                                )

                                trusted = trusted_footer_values(
                                    [{"text": t, "box": []} for t in footer_texts]
                                )
                                trusted_texts = [x["text"] for x in trusted]
                                if trusted_texts:
                                    existing_norm = {
                                        re.sub(r"[^A-Za-z0-9]", "", t).upper() for t in page_lines
                                    }
                                    for text in trusted_texts:
                                        key = re.sub(r"[^A-Za-z0-9]", "", text).upper()
                                        if key not in existing_norm:
                                            page_lines.append(text)
                                            existing_norm.add(key)
                                else:
                                    print(f"[FOOTER] Trang {page_number}: bo qua ket qua footer khong dang tin cay")

                        except Exception as exc:
                            print(f"[FOOTER] Trang {page_number}: loi OCR vung tang cuong (bo qua): {exc}")

                    print(f"[TIME] Trang {page_number} - Footer enhance + OCR: {time.time() - t_footer:.2f}s")

            else:
                print(
                    f"[SKIP] Trang {page_number}: bo qua VietOCR (loai='{page_type}' "
                    f"khong thuoc nhu cau cua document_type='{document_type}')"
                )

            page_text = "\n".join(page_lines).strip()

            pages.append({
                "page": page_number,
                "text": page_text,
                "type": page_type,
                "vietocr_ran": should_run_vietocr,
            })

            print(f"[TIME] Trang {page_number}: {time.time() - t_page:.2f}s")

    finally:
        doc.close()

    final_raw_text = "\n\n".join(page["text"] for page in pages if page["text"])

    print()
    print("=" * 70)
    print("KET QUA OCR PDF")
    print("=" * 70)

    for page in pages:
        print()
        print(
            f"---------------- TRANG {page['page']} (loai: {page['type']}, "
            f"vietocr_ran: {page['vietocr_ran']}) ----------------"
        )
        print(page["text"] or "(bo qua - khong chay VietOCR)")

    print()
    print("=" * 70)
    print("KET THUC OCR PDF")
    print("=" * 70)

    return {"raw_text": final_raw_text, "pages": pages, "page_count": len(pages)}


@app.post("/ocr-pdf")
async def run_ocr_pdf(
    file: UploadFile = File(...),
    document_type: str | None = Form(None),
):

    contents = await file.read()

    loop = asyncio.get_event_loop()

    try:
        result = await loop.run_in_executor(executor, _process_ocr_pdf, contents, document_type)
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"Loi xu ly OCR PDF: {exc}")

    return result


def dedupe_lines(lines):
    seen = set()
    out = []
    for l in lines:
        t = (l.get("text") or "").strip().lower()
        if not t or t in seen:
            continue
        seen.add(t)
        out.append(l)
    return out
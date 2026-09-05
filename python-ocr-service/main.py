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

Đổi sang model nhẹ hơn cho PaddleOCR (THÊM MỚI - dựa trên log thực tế):
    - Log thực tế cho thấy dòng "[TIME] PaddleOCR detect" KHÔNG hề cố
      định dù luôn detect trên cùng 1 shape 960x960 cố định — nó tăng
      gần như tỉ lệ thuận theo SỐ VÙNG CHỮ tìm được trong ảnh (34 vùng
      -> 24.73s, 25 vùng -> 12.87s, 18 vùng -> 13.87s). Một bước detect
      thuần (1 lần forward CNN trên 1 ảnh cố định shape) không có lý do
      gì phải phụ thuộc vào số lượng chữ tìm thấy.
    - Nguyên nhân: PaddleOCR(...) là 1 PIPELINE đầy đủ (detect + nhận
      diện chữ), không có cách nào tắt bước nhận diện (rec) khi dùng
      class PaddleOCR trực tiếp -> mỗi lần predict() đều tự chạy model
      rec (mặc định "PP-OCRv6_medium_rec" cho lang="vi") cho TỪNG vùng
      chữ vừa detect được, RỒI VỨT ĐI kết quả đó (VietOCR mới là thứ
      thực sự dùng để đọc nội dung). Đây chính là phần thời gian "ẩn"
      bị cộng dồn vào dòng log "[TIME] PaddleOCR detect".
    - Fix bước 1 (áp dụng ở đây, rủi ro thấp, không đổi cấu trúc code):
      đổi từ model "medium" (mặc định khi chỉ truyền lang="vi") sang
      các bản nhẹ hơn trong CÙNG họ PP-OCRv6 (chỉ có 3 mức:
      medium/small/tiny — KHÔNG có bản "mobile" như PP-OCRv5/v4/v3).
      Dùng "small" cho detect (giữ độ nhạy phát hiện box tốt hơn) và
      "tiny" cho rec (nhẹ nhất, vì kết quả rec này vẫn bị vứt đi, không
      ảnh hưởng độ chính xác cuối cùng). Bước rec vẫn chạy nhưng nhẹ
      hơn hẳn nên tổng thời gian giảm đáng kể.
    - Fix triệt để hơn (KHÔNG áp dụng ở bản này, cần đổi API + test lại
      field trả về): dùng module standalone paddleocr.TextDetection
      thay cho class PaddleOCR đầy đủ, để bỏ hẳn model rec ra khỏi
      luồng /ocr (ảnh đơn) — /ocr-pdf vẫn cần rec_texts để
      classify_page_quick() hoạt động nên giữ nguyên PaddleOCR pipeline
      cho luồng đó, hoặc chấp nhận mất tính năng skip trang (đã có sẵn
      fallback an toàn: chạy VietOCR cho mọi trang nếu không đọc được
      rec_texts).

Tăng cường riêng vùng "Số hiệu/Số vào sổ" trên bằng tốt nghiệp (THÊM MỚI):
    - Vùng chữ "Số hiệu/Serial No." và "Số vào sổ cấp bằng/Reg. No." trên
      phôi bằng luôn in cỡ chữ NHỎ HƠN HẲN phần còn lại của văn bằng
      (~10-14px chiều cao chữ trên ảnh gốc cỡ 500-800px chiều rộng).
      OCR chung 1 lần với toàn ảnh khiến vùng này hay bị PaddleOCR lọc
      bỏ vì box quá nhỏ, hoặc VietOCR đọc sai gần hết ký tự do crop quá
      nhỏ/mờ (case thực tế: "Số hiệu" -> "Số Hiện", "Serial No." ->
      "Sental Nam", số bị đọc sai lung tung).
    - Giải pháp: với ảnh nghi là văn bằng (đoán qua tên file Laravel đặt,
      vd "..._diploma_transcript_..."), CẮT RIÊNG dải ngang góc dưới ảnh
      (theo % chiều cao, đã đo trên mẫu thật), phóng to x4 + CLAHE +
      unsharp mask, rồi chạy LẠI đúng pipeline PaddleOCR detect + VietOCR
      recognize (dùng lại y hệt extract_lines() hiện có, KHÔNG viết logic
      riêng) trên ảnh đã tăng cường này. Kết quả được CHÈN LÊN ĐẦU danh
      sách "lines" của ảnh gốc — vì BuddhistDiplomaExtractor/DiplomaExtractor
      bên PHP lấy NHÃN ĐẦU TIÊN tìm thấy trong text, nên bản đọc rõ hơn
      (từ vùng đã tăng cường) sẽ được ưu tiên dùng thay vì bản mờ đọc
      được từ lần OCR ảnh gốc.
    - Đây là bước TĂNG CƯỜNG, không phải bước THAY THẾ: nếu OCR trên vùng
      tăng cường lỗi/rỗng, code tự bỏ qua và giữ nguyên kết quả từ ảnh
      gốc như trước — không làm hỏng luồng OCR chính.

Nhiều biến thể phôi bằng cho vùng "Số hiệu/Số vào sổ" (THÊM MỚI):
    - Dải % chiều cao cố định (FOOTER_Y_START_RATIO/FOOTER_Y_END_RATIO)
      chỉ được đo + test trên 2 mẫu bằng Phật học kiểu Vietnam Buddhist
      University (bản chỉ tiếng Việt 503x590 và bản song ngữ 763x590).
      Với mẫu phôi bằng khác (vd "Triết học Phật giáo", aspect ratio gốc
      656x467 khác hẳn), dòng "Số hiệu/Số vào sổ" nằm SÁT MÉP DƯỚI hơn
      nhiều, ra ngoài dải 0.78-0.95 cố định -> vùng crop ra không chứa
      đúng nội dung cần đọc (đọc ra toàn rác không liên quan gì tới
      "số hiệu"/"số vào sổ"), khác biệt với lỗi VietOCR-đọc-sai-ký-tự
      (trường hợp đó vẫn còn giữ được khung nhãn nhận diện được).
    - Giải pháp CŨ (vẫn giữ làm fallback — xem hàm find_footer_anchor_y()
      bên dưới cho giải pháp CHÍNH mới): thay 1 dải cố định bằng danh
      sách FOOTER_VARIANTS, mỗi biến thể gắn với 1 vài từ khoá đặc
      trưng của ĐÚNG mẫu phôi bằng đó (vd "triết học phật giáo",
      "buddhist philosophy"). Hàm pick_footer_ratios() so khớp các từ
      khoá này trên text ĐÃ OCR ĐƯỢC Ở LẦN ĐỌC TOÀN ẢNH ĐẦU TIÊN
      (lines/page_lines đã có sẵn, KHÔNG tốn thêm chi phí OCR nào để
      lấy tín hiệu phân loại này), rồi trả về đúng dải y_start/y_end
      của biến thể khớp. Nếu không khớp biến thể nào, dùng "default"
      (giữ nguyên giá trị đã đo cho mẫu VBU cũ).
    - VẤN ĐỀ CỦA GIẢI PHÁP CŨ (lý do thêm find_footer_anchor_y()): vẫn
      là ĐOÁN MÙ theo % cố định cho từng "biến thể" phải đo tay thủ
      công trên từng mẫu ảnh — mỗi ảnh scan/chụp thực tế lại có tỉ lệ,
      margin, độ nghiêng khác nhau nên cùng 1 mẫu phôi vẫn có thể lệch
      %, và mẫu hoàn toàn mới (chưa từng đo) luôn rơi vào "default" và
      gần như chắc chắn cắt sai.
    - GIẢI PHÁP MỚI (ưu tiên dùng, xem find_footer_anchor_y()): thay vì
      đoán % cố định, dùng NGAY toạ độ box THẬT của các dòng đã OCR
      được ở lượt đọc toàn ảnh đầu tiên — tìm dòng nào chứa (dù chỉ một
      phần, do fuzzy match không dấu) các từ khoá liên quan nhãn "Số
      hiệu"/"Serial"/"Số vào sổ"/"Reg", lấy toạ độ y thật của dòng đó
      làm neo, rồi crop dải NGAY QUANH toạ độ này (có margin). Cách này
      tự thích nghi với TỪNG ảnh cụ thể, không cần đo tay % cho từng
      mẫu phôi mới. pick_footer_ratios() theo biến thể CŨ chỉ còn dùng
      làm fallback khi không tìm được anchor thật nào (case OCR đọc nát
      hoàn toàn, không còn dấu vết gì của nhãn) — không phá vỡ hành vi
      đã có.
    - LƯU Ý: dải của biến thể mới (vd "triet_hoc_phat_giao") CHỈ LÀ ƯỚC
      LƯỢNG dựa trên quan sát 1 ảnh mẫu, CHƯA được đo + test trên nhiều
      mẫu thật như dải "default". Cần thu thập thêm mẫu và tinh chỉnh
      lại 2 số y_start/y_end này trước khi tin tưởng hoàn toàn — đây là
      NƠI DUY NHẤT cần sửa nếu vẫn crop sai vùng cho biến thể đó (khi
      rơi xuống fallback này).
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

# ============================================================
# THÊM MỚI: CẤU HÌNH TĂNG CƯỜNG VÙNG "SỐ HIỆU / SỐ VÀO SỔ"
# ============================================================
#
# Xem giải thích đầy đủ ở docstring đầu file (mục "Tăng cường riêng
# vùng Số hiệu/Số vào sổ trên bằng tốt nghiệp").

# Bật/tắt tính năng này. Đặt False để quay lại hành vi cũ (chỉ OCR 1
# lần trên toàn ảnh) mà không cần xoá code.
FOOTER_ENABLE = True

# Chỉ chạy thêm bước này cho ảnh NGHI LÀ văn bằng (đoán qua tên file
# Laravel đặt, vd "applicant_38_diploma_transcript_...") — tránh tốn
# thêm thời gian vô ích cho CCCD/phiếu đăng ký (vốn không có 2 dòng
# "Số hiệu/Số vào sổ" này để mà tăng cường).
#
# LƯU Ý: endpoint /ocr hiện KHÔNG nhận document_type (chỉ /ocr-pdf mới
# có). Nếu sau này bạn thêm document_type vào /ocr, có thể thay điều
# kiện đoán-qua-tên-file này bằng kiểm tra document_type cho chính xác
# hơn (ví dụ document_type == "diploma_transcript").
FOOTER_FILENAME_KEYWORDS = ("diploma", "bang", "transcript")

# Vị trí dải cắt MẶC ĐỊNH, tính theo % chiều cao ảnh recognition_image
# (0.0 = đỉnh ảnh, 1.0 = đáy ảnh). Đã đo + test trực tiếp trên mẫu phôi
# bằng thật (bản chỉ tiếng Việt 503x590 và bản song ngữ 763x590) — cả 2
# đều crop trúng đúng dải chứa "Số hiệu"/"Số vào sổ". Cắt FULL CHIỀU
# RỘNG (không cắt theo x) vì vị trí bắt đầu theo chiều ngang khác nhau
# tuỳ loại phôi (1 cột hay song ngữ 2 cột), trong khi vị trí theo chiều
# dọc (% chiều cao) ổn định hơn nhiều giữa các loại phôi CÙNG NHÓM.
#
# LƯU Ý: đây chỉ còn là giá trị "default" — DÙNG LÀM FALLBACK CUỐI CÙNG
# khi find_footer_anchor_y() (giải pháp chính, dựa trên toạ độ box
# THẬT) không tìm được anchor nào. Xem FOOTER_VARIANTS +
# pick_footer_ratios() bên dưới.
FOOTER_Y_START_RATIO = 0.78
FOOTER_Y_END_RATIO = 0.95

# Hệ số phóng to vùng đã cắt trước khi OCR lại.
FOOTER_UPSCALE = 4

# ============================================================
# THÊM MỚI: NHIỀU BIẾN THỂ PHÔI BẰNG PHẬT GIÁO (vị trí dòng
# "Số hiệu/Số vào sổ" khác nhau tuỳ mẫu — xem giải thích đầy đủ ở
# docstring đầu file, mục "Nhiều biến thể phôi bằng...").
#
# LƯU Ý: từ khi có find_footer_anchor_y() (neo theo toạ độ box THẬT),
# danh sách này chỉ còn là FALLBACK khi không tìm được anchor thật nào
# trong ảnh — không còn là cơ chế chính để chọn vùng crop nữa.
# ============================================================
#
# Mỗi entry:
#   name      : tên biến thể (chỉ để log/debug)
#   keywords  : các cụm từ khoá (KHÔNG DẤU, viết thường) đặc trưng cho
#               ĐÚNG mẫu phôi bằng đó — so khớp trên bản đã
#               strip_diacritics_vn() của text ĐÃ OCR ĐƯỢC Ở LẦN ĐỌC
#               TOÀN ẢNH ĐẦU TIÊN (không tốn thêm chi phí OCR).
#   y_start / y_end : dải % chiều cao dùng cho biến thể này.
#
# "default" LUÔN đứng CUỐI danh sách, dùng làm phương án dự phòng khi
# không khớp keyword của biến thể nào ở trên — giữ nguyên giá trị đã đo
# cho mẫu VBU cũ (FOOTER_Y_START_RATIO/FOOTER_Y_END_RATIO), không đổi
# hành vi của các mẫu đang chạy tốt.
#
# CẢNH BÁO: dải của "triet_hoc_phat_giao" là ƯỚC LƯỢNG dựa trên quan
# sát 1 ảnh mẫu DUY NHẤT, CHƯA được đo + test trên nhiều mẫu thật như
# dải "default" — cần thu thập thêm mẫu bằng "Triết học Phật giáo"
# khác và tinh chỉnh lại 2 số này nếu vẫn crop sai vùng.
FOOTER_VARIANTS = [
    {
        "name": "triet_hoc_phat_giao",
        "keywords": ("triet hoc phat giao", "buddhist philosophy"),
        # SỬA (lần 2 - vẫn là ước lượng, xem log [DEBUG-Y] để tự đo
        # lại chính xác nếu vẫn chưa trúng): dải 0.90-0.99 cũ crop lố
        # xuống quá đáy, dính hoa văn viền thay vì dòng "Số hiệu".
        # Thu hẹp + dịch lên cao hơn.
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
    """
    Chọn dải % chiều cao (y_start, y_end) để crop vùng "Số hiệu/Số
    vào sổ", dựa trên nội dung ĐÃ ĐỌC ĐƯỢC ở lần OCR toàn ảnh đầu
    tiên (existing_text — nối các "text" trong "lines"/"page_lines"
    bằng "\n", đã có sẵn TRƯỚC KHI bước tăng cường footer chạy).

    LƯU Ý: đây giờ chỉ còn là FALLBACK — nơi gọi nên ưu tiên thử
    find_footer_anchor_y() (neo theo toạ độ box THẬT) trước, chỉ rơi
    xuống hàm này khi không tìm được anchor thật nào.

    Không tốn thêm chi phí OCR nào để lấy tín hiệu phân loại này —
    chỉ so khớp chuỗi (đã bỏ dấu, viết thường) trên text đã có.

    Nếu không khớp keyword của biến thể nào, trả về dải "default"
    (hành vi y hệt code cũ, không ảnh hưởng các mẫu đã hoạt động tốt).
    """

    t = strip_diacritics_vn(existing_text)

    for variant in FOOTER_VARIANTS:
        if variant["name"] == "default":
            continue

        if any(kw in t for kw in variant["keywords"]):
            if DEBUG_FILTER:
                print(
                    f"[FOOTER] Nhan dien bien the: {variant['name']} "
                    f"(y_start={variant['y_start']}, "
                    f"y_end={variant['y_end']})"
                )
            return variant["y_start"], variant["y_end"]

    default = FOOTER_VARIANTS[-1]
    return default["y_start"], default["y_end"]


# ============================================================
# THÊM MỚI: NEO VÙNG CROP THEO TOẠ ĐỘ BOX THẬT (giải pháp chính,
# thay cho đoán mù % cố định — xem giải thích đầy đủ ở docstring
# đầu file, mục "Nhiều biến thể phôi bằng...").
# ============================================================

FOOTER_ANCHOR_KEYWORDS = (
    "so hieu",
    "so hiew",
    "hieu bang",
    "serial",
    "so vao so",
    "vao so cap bang",
    "reg no",
    "reg.",
    "so vho",  # biến thể lỗi OCR đã gặp thực tế (a->h trong "vao")
)

# THÊM MỚI (TIER 2 — xem giải thích ở find_footer_anchor_y()): case
# thực tế gặp phải (mẫu "Triết học Phật giáo") cho thấy đôi khi nhãn bị
# OCR đọc NÁT HOÀN TOÀN, không còn giữ lại bất kỳ mảnh nào của "hieu"/
# "vao so" (case thực tế: "Số hiệu/Số vào sổ..." -> "bo nhachp bing/
# Xua" — không có ký tự nào trùng khớp FOOTER_ANCHOR_KEYWORDS). Khi đó
# tier 1 (khớp theo nhãn) chắc chắn thất bại dù toạ độ box vẫn đúng.
#
# Danh sách loại trừ: các cụm nội dung THƯỜNG XUẤT HIỆN ở vùng đáy ảnh
# văn bằng nhưng chắc chắn KHÔNG PHẢI dòng "Số hiệu/Số vào sổ" (tên
# người ký, chức danh, quốc hiệu, câu boilerplate chứng thực...) — dùng
# để loại các dòng này ra khỏi ứng viên tier 2, dù chúng có thể tình cờ
# chứa số (vd ngày ký "20-12-2022").
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
    "hon thuong",  # biến thể OCR đọc sai "Hòa thượng"
    "vien truong",
    "rector",
    "university",
    "buddhist",
    "ngay",  # dòng ngày ký công chứng dạng "Ngày...20-12-2022"
)


def find_footer_anchor_by_shape(
    lines: list[dict],
    image_height: int,
) -> tuple[float, float] | None:
    """
    THÊM MỚI (TIER 2 — dùng khi tier 1 theo nhãn thất bại): thay vì đòi
    hỏi phải nhận ra CHỮ của nhãn (tier 1 — find_footer_anchor_y()),
    hàm này neo theo HÌNH DẠNG + VỊ TRÍ: dòng "Số hiệu/Số vào sổ" luôn
    là 1 trong những dòng SÁT ĐÁY ẢNH NHẤT (dưới toàn bộ chữ ký/con dấu
    chứng thực) và luôn chứa CHỮ SỐ — bất kể nhãn có đọc đúng hay không,
    vì đây là ĐẶC ĐIỂM VẬT LÝ của tấm ảnh, không phụ thuộc OCR đọc đúng
    chữ gì.

    Chỉ xét các dòng có y_top nằm trong 15% CUỐI CÙNG của ảnh (>= 0.85)
    để không vô tình bắt nhầm các field khác nằm giữa ảnh (ngày sinh,
    năm tốt nghiệp...). Trong vùng đó, loại bỏ các dòng khớp
    FOOTER_SHAPE_DENYLIST (tên người ký/chức danh/câu chứng thực — biết
    chắc không phải mã số), chỉ giữ lại dòng có ít nhất 1 chữ số.

    Trả về None nếu không có dòng nào thoả — nơi gọi tự rơi xuống tier
    3 (pick_footer_ratios() theo biến thể % cố định).
    """

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

    # Margin nhỏ hơn tier 1 vì đã neo khá sát vùng thật (dòng gần đáy
    # nhất có chữ số), chỉ cần đệm thêm chút để không hụt mất phần
    # nhãn đứng ngay trên (thường không có số) hoặc phần giá trị bị
    # tràn xuống dòng cuối cùng của ảnh.
    margin_up = image_height * 0.03
    margin_down = image_height * 0.05

    y_start = max(0.0, (y_top - margin_up) / image_height)
    y_end = min(1.0, (y_bottom + margin_down) / image_height)

    return y_start, y_end


def find_footer_anchor_y(
    lines: list[dict],
    image_height: int,
) -> tuple[float, float] | None:
    """
    THÊM MỚI (thay cho đoán mù % theo biến thể): tìm NGAY vị trí thật
    của dòng chứa nhãn "Số hiệu"/"Serial"/"Số vào sổ"/"Reg" trong danh
    sách `lines` đã OCR được ở lượt đọc toàn ảnh đầu tiên (có toạ độ
    box THẬT, không phải suy đoán %), rồi trả về dải crop NGAY QUANH
    toạ độ đó. Dùng fuzzy match không dấu (FOOTER_ANCHOR_KEYWORDS) nên
    vẫn bắt được khi nhãn bị OCR đọc sai 1 phần (vd "Số hiệu" -> "Số
    hiện", vẫn còn "hieu"/"hien" gần đúng).

    Vì mỗi ảnh tự định vị đúng vị trí của chính nó dựa trên nội dung
    thật đã OCR được, cách này KHÔNG cần đo tay % cho từng mẫu phôi
    mới như FOOTER_VARIANTS/pick_footer_ratios() — tự thích nghi với
    layout của từng ảnh cụ thể.

    FIX MỚI (TIER 2 — case thực tế: nhãn bị OCR đọc NÁT HOÀN TOÀN,
    "Số hiệu/Số vào sổ..." -> "bo nhachp bing/ Xua", không còn giữ lại
    mảnh nào của "hieu"/"vao so" để tier 1 (khớp theo nhãn) bắt được):
    nếu tier 1 không tìm thấy gì, thử tiếp find_footer_anchor_by_shape()
    — neo theo VỊ TRÍ (sát đáy ảnh) + HÌNH DẠNG (có chữ số, không phải
    tên người ký/chức danh đã biết) thay vì đòi đọc đúng chữ nhãn.

    Trả về None nếu CẢ 2 TIER đều không tìm thấy gì — nơi gọi tự rơi về
    fallback pick_footer_ratios() theo biến thể % cố định (tier 3,
    không phá vỡ hành vi hiện có cho các mẫu chưa từng gặp).
    """

    best_y_top = None
    best_y_bottom = None

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

        # Ưu tiên dòng ĐẦU TIÊN xuất hiện theo vị trí (thường "Số hiệu"
        # đứng trước "Số vào sổ" trên phôi bằng), nhưng thực ra chỉ cần
        # 1 anchor đủ tin cậy để định vị dải crop — không cần tách
        # riêng 2 nhãn ở bước này vì bước enhance chỉ cần "trúng vùng",
        # còn việc tách 2 field vẫn do regex PHP đảm nhiệm trên text đã
        # rõ hơn.
        if best_y_top is None or y_top < best_y_top:
            best_y_top = y_top
            best_y_bottom = y_bottom

    if best_y_top is not None:
        # Margin: mở rộng LÊN một chút (phòng giá trị thật nằm NGAY
        # TRÊN nhãn — case "OCR đảo thứ tự" đã từng gặp bên
        # DiplomaExtractor PHP), và mở rộng XUỐNG nhiều hơn (layout phổ
        # biến nhất: giá trị nằm ngay dưới nhãn, có khi 2 dòng: Số hiệu
        # + Số vào sổ).
        margin_up = image_height * 0.03
        margin_down = image_height * 0.12

        y_start = max(0.0, (best_y_top - margin_up) / image_height)
        y_end = min(1.0, (best_y_bottom + margin_down) / image_height)

        return y_start, y_end

    # TIER 1 thất bại (nhãn bị đọc nát hoàn toàn) -> thử TIER 2.
    return find_footer_anchor_by_shape(lines, image_height)


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

    # SỬA (THÊM MỚI - xem giải thích ở docstring đầu file): chỉ định rõ
    # model nhẹ hơn thay vì để PaddleOCR tự chọn model "medium" mặc
    # định cho lang="vi". Class PaddleOCR luôn chạy CẢ detect lẫn rec
    # bên trong predict() (không có cách tắt rec khi dùng class này) —
    # mà bước rec ở đây hoàn toàn bị vứt bỏ kết quả (VietOCR mới là
    # engine đọc nội dung thật). LƯU Ý: họ PP-OCRv6 chỉ có 3 mức
    # medium/small/tiny, KHÔNG có "mobile" như PP-OCRv5/v4/v3 (dùng
    # "mobile" sẽ lỗi UnknownModelError).
    #
    # - det: dùng "small" (không dùng "tiny") để giữ độ nhạy phát hiện
    #   box tốt hơn — det ảnh hưởng trực tiếp đến việc có bắt được đủ
    #   vùng chữ (vd "Nam"/"Nữ") hay không.
    # - rec: dùng "tiny" (nhẹ nhất) vì kết quả rec này bị vứt đi ngay,
    #   không ảnh hưởng độ chính xác cuối cùng (VietOCR mới là engine
    #   đọc nội dung thật).
    #
    # Nếu sau khi test thấy độ chính xác detect bị ảnh hưởng (bỏ sót
    # box), có thể đổi text_detection_model_name lại thành
    # "PP-OCRv6_medium_det" (giữ nguyên rec là "tiny").
    text_detection_model_name="PP-OCRv6_small_det",
    text_recognition_model_name="PP-OCRv6_tiny_rec",

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
# THÊM MỚI: TĂNG CƯỜNG VÙNG "SỐ HIỆU / SỐ VÀO SỔ"
# ============================================================
#
# Xem giải thích đầy đủ ở docstring đầu file. Hàm này CHỈ crop + tăng
# cường ảnh — việc detect/recognize vẫn tái dùng nguyên xi
# resize_and_pad() + ocr_engine.predict() + extract_lines() đã có sẵn,
# không viết logic OCR riêng ở đây.

def crop_and_enhance_footer(
    recognition_image: np.ndarray,
    y_start_ratio: float = FOOTER_Y_START_RATIO,
    y_end_ratio: float = FOOTER_Y_END_RATIO,
    scale: int = FOOTER_UPSCALE,
):
    """
    Cắt dải ngang góc dưới ảnh văn bằng (full chiều rộng, theo %
    chiều cao) rồi phóng to + KHỬ MỰC ĐỎ CON DẤU + CLAHE (tăng tương
    phản cục bộ) + unsharp mask (làm nét) để OCR đọc chính xác hơn
    vùng chữ nhỏ "Số hiệu/Số vào sổ".

    THÊM MỚI (KHỬ MỰC ĐỎ CON DẤU): case thực tế gặp phải — dòng "Số
    hiệu/Serial No." và "Số vào sổ/Reg. No." bị chính con dấu đỏ của
    trường/học viện đóng ĐÈ TRỰC TIẾP lên trên (không phải lệch vùng
    crop — vùng crop đã trúng đúng dòng), khiến nét mực đỏ cắt ngang
    làm vỡ hình dạng ký tự đen bên dưới, VietOCR đọc ra toàn rác
    ("bo nhachp bing/ Xua"...) dù vùng crop hoàn toàn chính xác.

    Ý tưởng: mực dấu là ĐỎ (kênh R cao, kênh G/B thấp), còn chữ in là
    ĐEN (cả 3 kênh đều thấp và gần bằng nhau). Thay vì convert thẳng
    ảnh RGB sang gray bằng công thức trung bình có trọng số thông
    thường (vốn vẫn cộng dồn một phần cường độ kênh R của mực đỏ vào
    ảnh xám, làm nét chữ bị nhiễu bởi nét dấu chồng lên), ta lấy
    min(G, B) làm ảnh xám:
        - Ở vùng chỉ có mực đỏ (không có chữ đen đè lên): G và B đều
          thấp -> min(G,B) thấp -> vùng đó gần như bị "làm mờ đi",
          giảm hẳn ảnh hưởng của nét dấu.
        - Ở vùng có chữ đen (kể cả đang nằm trên nền có dấu đỏ): cả
          3 kênh đều thấp do chữ đen -> G và B đều thấp -> min(G,B)
          thấp -> giữ lại đúng nét chữ.
    Cách này KHÔNG hoàn hảo (nếu dấu đè kín 100% một ký tự, nét chữ ở
    đúng điểm đó vẫn mất — đây là giới hạn vật lý, không phải lỗi
    code), nhưng làm giảm đáng kể nhiễu do phần mực đỏ KHÔNG chồng
    trực tiếp lên chữ (viền dấu, các nét dấu nằm ngoài vùng chữ).

    THÊM MỚI TRƯỚC ĐÓ: y_start_ratio/y_end_ratio do nơi gọi truyền vào
    (xem find_footer_anchor_y() — ưu tiên — hoặc pick_footer_ratios()
    — fallback) thay vì luôn dùng đúng 1 dải cố định — để hỗ trợ nhiều
    biến thể phôi bằng có vị trí dòng "Số hiệu/Số vào sổ" khác nhau.
    Giá trị mặc định của tham số vẫn giữ nguyên dải "default" (đã đo
    cho mẫu VBU) để không đổi hành vi nếu ai gọi hàm này mà không
    truyền tham số.

    LƯU Ý QUAN TRỌNG: recognition_image trong pipeline này là ảnh RGB
    (không phải BGR — xem comment trong extract_lines() bước 8: hàm
    preprocess() tạo ảnh RGB từ PIL, các bước xử lý sau đó trong file
    này đều giữ nguyên convention RGB, không convert sang BGR ở đâu
    cả). Vì vậy split() bên dưới lấy đúng thứ tự (R, G, B), không phải
    (B, G, R).

    Trả về None nếu ảnh đầu vào quá nhỏ / crop rỗng — nơi gọi hàm này
    cần tự kiểm tra None và bỏ qua bước tăng cường, KHÔNG raise lỗi làm
    hỏng luồng OCR ảnh gốc (đây là bước tăng cường thêm, không phải bước
    bắt buộc).
    """

    if recognition_image is None or recognition_image.size == 0:
        return None

    h, w = recognition_image.shape[:2]

    y1 = max(0, int(h * y_start_ratio))
    y2 = min(h, int(h * y_end_ratio))

    if y2 <= y1:
        return None

    footer = recognition_image[y1:y2, 0:w]

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

    # --------------------------------------------------------------
    # THÊM MỚI: KHỬ MỰC ĐỎ CON DẤU trước khi chuyển sang gray thường.
    #
    # upscaled đang là ảnh RGB 3 kênh (xem LƯU Ý ở docstring).
    # min(G, B) giữ lại nét chữ đen, giảm ảnh hưởng của mực đỏ ở
    # những vùng con dấu không trực tiếp chồng lên ký tự.
    #
    # Có bọc try/except phòng trường hợp ảnh đầu vào bất thường (vd
    # chỉ có 1 kênh) — nếu lỗi thì rơi về gray thường (COLOR_RGB2GRAY)
    # như hành vi cũ, không làm hỏng luồng OCR.
    # --------------------------------------------------------------
    try:
        r_ch, g_ch, b_ch = cv2.split(upscaled)
        gray = cv2.min(g_ch, b_ch)
    except Exception as exc:
        if DEBUG_FILTER:
            print(
                f"[FOOTER] Loi khi khu muc do (fallback ve gray "
                f"thuong): {exc}"
            )
        gray = cv2.cvtColor(upscaled, cv2.COLOR_RGB2GRAY)

    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    enhanced = clahe.apply(gray)

    blurred = cv2.GaussianBlur(enhanced, (0, 0), sigmaX=3)
    sharpened = cv2.addWeighted(enhanced, 1.5, blurred, -0.5, 0)

    # Grayscale -> 3 kênh: cả 3 kênh giống hệt nhau nên RGB/BGR cho
    # cùng 1 kết quả pixel ở bước convert này, không cần phân biệt.
    footer_rgb = cv2.cvtColor(sharpened, cv2.COLOR_GRAY2RGB)

    # THÊM MỚI (CHỈ ĐỂ DEBUG): lưu thêm ảnh trung gian sau bước khử
    # mực đỏ (trước CLAHE/sharpen) để so sánh trực quan với ảnh cropped
    # cuối cùng đã có sẵn — giúp đánh giá bước khử đỏ có thực sự tách
    # được chữ ra khỏi dấu hay không, hay dấu đè quá nặng không cứu
    # được (trường hợp đó vẫn cần nhập tay, không phải lỗi code).
    if DEBUG_FILTER:
        try:
            os.makedirs("debug_footer", exist_ok=True)
            Image.fromarray(gray).save(
                "debug_footer/_last_footer_red_suppressed_gray.jpg"
            )
        except Exception as exc:
            print(f"[DEBUG-FOOTER] Loi khi luu anh khu do: {exc}")

    return footer_rgb


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
    # 7.5 THÊM MỚI: TĂNG CƯỜNG RIÊNG VÙNG "SỐ HIỆU / SỐ VÀO SỔ"
    #
    # Chỉ chạy cho ảnh nghi là văn bằng (theo tên file). OCR lại lần 2
    # trên vùng góc dưới đã crop + phóng to + tăng nét (dùng lại đúng
    # resize_and_pad() + ocr_engine.predict() + extract_lines() ở
    # trên, không viết logic detect/recognize riêng), rồi CHÈN LÊN ĐẦU
    # danh sách lines (sau khi đã sort theo vị trí ở bước 7) — vì
    # DiplomaExtractor/BuddhistDiplomaExtractor bên PHP lấy NHÃN ĐẦU
    # TIÊN tìm thấy trong text, nên bản đọc rõ hơn này sẽ được ưu tiên
    # dùng thay vì bản mờ đọc được từ ảnh gốc.
    #
    # Đặt SAU bước sort (không sort chung box của 2 ảnh khác nhau) vì
    # box của footer_lines thuộc hệ toạ độ ảnh crop riêng (không khớp
    # không gian thật với ảnh gốc) — trộn chung vào bước sort theo vị
    # trí phía trên sẽ cho thứ tự vô nghĩa. Việc chèn thẳng lên đầu
    # (không cần đúng vị trí không gian) là đủ, vì PHP extractor chỉ
    # cần thứ tự XUẤT HIỆN trong text, không cần đúng toạ độ trên ảnh.
    #
    # SỬA (THAY ĐỔI CHÍNH): chọn dải crop (y_start/y_end) bằng cách ƯU
    # TIÊN neo theo toạ độ box THẬT của các dòng đã OCR được ở "lines"
    # (find_footer_anchor_y()) — chỉ khi không tìm được anchor thật nào
    # mới rơi xuống pick_footer_ratios() theo biến thể % cố định cũ.
    # Xem giải thích đầy đủ ở docstring đầu file.
    # ========================================================

    is_diploma_like = bool(filename) and any(
        kw in filename.lower() for kw in FOOTER_FILENAME_KEYWORDS
    )

    if FOOTER_ENABLE and is_diploma_like:

        t_footer = time.time()

        # THÊM MỚI (CHỈ ĐỂ DEBUG): in ra toạ độ y (tính theo % chiều
        # cao recognition_image) của MỌI dòng đã OCR được, để tự đo
        # chính xác dải y_start/y_end cần cho FOOTER_VARIANTS thay vì
        # đoán qua ảnh chụp màn hình. Cột "y_top%" ứng với đỉnh box
        # (box[0][1] - toạ độ y của điểm góc trên-trái). Tìm dòng nào
        # gần với "Số hiệu"/"Serial No." nhất trong "KET QUA OCR" bên
        # dưới, đối chiếu ngược lại đúng dòng đó ở đây để lấy y_top%.
        if DEBUG_FILTER:
            recog_h = recognition_image.shape[0]
            print("[DEBUG-Y] Toa do y (%) cua tung dong (recognition_image):")
            for line in lines:
                if line.get("text") and line.get("box"):
                    y_top_pct = line["box"][0][1] / recog_h
                    print(
                        f"[DEBUG-Y]   y_top%={y_top_pct:.3f} "
                        f"| text={line['text']!r}"
                    )

        recog_h = recognition_image.shape[0]
        anchor_ratios = find_footer_anchor_y(lines, recog_h)

        if anchor_ratios is not None:
            footer_y_start, footer_y_end = anchor_ratios
            if DEBUG_FILTER:
                print(
                    f"[FOOTER] Dung ANCHOR THAT (toa do box that): "
                    f"y_start={footer_y_start:.3f}, "
                    f"y_end={footer_y_end:.3f}"
                )
        else:
            existing_text = "\n".join(
                line["text"] for line in lines if line.get("text")
            )
            footer_y_start, footer_y_end = pick_footer_ratios(existing_text)
            if DEBUG_FILTER:
                print(
                    "[FOOTER] Khong tim thay anchor that, fallback bien the: "
                    f"y_start={footer_y_start:.3f}, "
                    f"y_end={footer_y_end:.3f}"
                )

        # THÊM MỚI (CHỈ ĐỂ DEBUG): lưu ra đĩa 2 ảnh để NHÌN TRỰC TIẾP
        # vùng crop thay vì đoán qua toạ độ % suy luận từ text đọc sai.
        #   *_footer_region.jpg : recognition_image gốc, có VẼ Ô ĐỎ
        #                         đánh dấu đúng vùng sắp bị cắt ra.
        #   *_footer_cropped.jpg: kết quả sau khi cắt + phóng to +
        #                         CLAHE + sharpen (ảnh THẬT SỰ đưa vào
        #                         OCR lần 2).
        # Mở 2 file này trong thư mục debug_footer/ (nằm cạnh main.py)
        # để biết chính xác vùng đang cắt có trúng dòng "Số hiệu/Số
        # vào sổ" hay không, từ đó chỉnh lại đúng y_start/y_end trong
        # FOOTER_VARIANTS mà không cần đoán mù nữa.
        if DEBUG_FILTER:
            try:
                os.makedirs("debug_footer", exist_ok=True)
                debug_name = os.path.splitext(filename or "unknown")[0]

                y1 = max(0, int(recog_h * footer_y_start))
                y2 = min(recog_h, int(recog_h * footer_y_end))

                region_preview = recognition_image.copy()
                cv2.rectangle(
                    region_preview,
                    (0, y1),
                    (region_preview.shape[1] - 1, y2),
                    (255, 0, 0),
                    3,
                )
                Image.fromarray(region_preview).save(
                    f"debug_footer/{debug_name}_footer_region.jpg"
                )
                print(
                    f"[DEBUG-FOOTER] Da luu vung crop (o do) vao "
                    f"debug_footer/{debug_name}_footer_region.jpg "
                    f"| y_start={footer_y_start} (y={y1}px) "
                    f"y_end={footer_y_end} (y={y2}px)"
                )
            except Exception as exc:
                print(f"[DEBUG-FOOTER] Loi khi luu anh debug: {exc}")

        try:
            footer_image = crop_and_enhance_footer(
                recognition_image,
                y_start_ratio=footer_y_start,
                y_end_ratio=footer_y_end,
            )
        except Exception as exc:
            footer_image = None
            print(f"[FOOTER] Loi khi crop/tang cuong (bo qua): {exc}")

        if DEBUG_FILTER and footer_image is not None:
            try:
                os.makedirs("debug_footer", exist_ok=True)
                debug_name = os.path.splitext(filename or "unknown")[0]
                Image.fromarray(footer_image).save(
                    f"debug_footer/{debug_name}_footer_cropped.jpg"
                )
            except Exception as exc:
                print(f"[DEBUG-FOOTER] Loi khi luu anh cropped: {exc}")

        if footer_image is not None:

            try:
                footer_detect_image, footer_det_scale, _, _ = resize_and_pad(
                    footer_image
                )

                with ocr_lock:
                    footer_results = list(
                        ocr_engine.predict(footer_detect_image)
                    )

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
                        f"[FOOTER] Doc them {len(footer_lines)} dong tu "
                        f"vung da tang cuong: "
                        f"{[l['text'] for l in footer_lines]}"
                    )
                    lines = footer_lines + lines
                else:
                    print(
                        "[FOOTER] Khong doc duoc dong nao tu vung tang cuong"
                    )

            except Exception as exc:
                print(
                    f"[FOOTER] Loi khi OCR vung tang cuong (bo qua): {exc}"
                )

        print(
            f"[TIME] Footer enhance + OCR: "
            f"{time.time() - t_footer:.2f}s"
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
            page_line_dicts = []  # THÊM MỚI: giữ dict (có box) cho anchor

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

                    # THÊM MỚI: giữ nguyên dict (có box) để anchor thật
                    # (find_footer_anchor_y()) có toạ độ để dùng bên
                    # dưới — page_lines (list string) không đủ thông
                    # tin toạ độ.
                    page_line_dicts.extend(lines)

                    page_lines.extend(
                        item["text"]

                        for item in lines

                        if item.get("text")
                    )

                # =============================================
                # 7.5 THÊM MỚI: TĂNG CƯỜNG VÙNG "SỐ HIỆU / SỐ
                # VÀO SỔ" CHO TRANG LÀ VĂN BẰNG
                #
                # Cùng logic như trong _process_ocr_image() —
                # tái dùng nguyên xi crop_and_enhance_footer() +
                # resize_and_pad() + extract_lines(), CHỈ áp dụng
                # cho trang đã được classify_page_quick() nhận
                # diện là 1 trong 3 loại bằng (page_type bắt đầu
                # bằng "bang_"), tránh tốn thời gian vô ích cho
                # trang CCCD/phiếu đăng ký.
                #
                # SỬA (THAY ĐỔI CHÍNH): ưu tiên neo theo toạ độ box
                # THẬT (find_footer_anchor_y()) dựa trên
                # page_line_dicts vừa đọc được ở vòng VietOCR ngay
                # trên; chỉ rơi xuống pick_footer_ratios() (biến thể
                # % cố định) khi không tìm được anchor thật nào.
                # =============================================

                if FOOTER_ENABLE and page_type.startswith("bang_"):

                    t_footer = time.time()

                    recog_h = recognition_image.shape[0]
                    anchor_ratios = find_footer_anchor_y(
                        page_line_dicts, recog_h
                    )

                    if anchor_ratios is not None:
                        footer_y_start, footer_y_end = anchor_ratios
                        print(
                            f"[FOOTER] Trang {page_number}: dung ANCHOR "
                            f"THAT | y_start={footer_y_start:.3f}, "
                            f"y_end={footer_y_end:.3f}"
                        )
                    else:
                        existing_text = "\n".join(page_lines)
                        footer_y_start, footer_y_end = pick_footer_ratios(
                            existing_text
                        )
                        print(
                            f"[FOOTER] Trang {page_number}: khong tim "
                            f"thay anchor that, fallback bien the | "
                            f"y_start={footer_y_start:.3f}, "
                            f"y_end={footer_y_end:.3f}"
                        )

                    try:
                        footer_image = crop_and_enhance_footer(
                            recognition_image,
                            y_start_ratio=footer_y_start,
                            y_end_ratio=footer_y_end,
                        )
                    except Exception as exc:
                        footer_image = None
                        print(
                            f"[FOOTER] Trang {page_number}: loi crop/tang "
                            f"cuong (bo qua): {exc}"
                        )

                    if footer_image is not None:

                        try:
                            (
                                footer_detect_image,
                                footer_det_scale,
                                _,
                                _,
                            ) = resize_and_pad(footer_image)

                            with ocr_lock:
                                footer_results = list(
                                    ocr_engine.predict(footer_detect_image)
                                )

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
                                    f"[FOOTER] Trang {page_number}: doc them "
                                    f"{len(footer_texts)} dong: {footer_texts}"
                                )
                                # Chèn lên đầu — cùng lý do như trong
                                # _process_ocr_image(): PHP extractor lấy
                                # nhãn ĐẦU TIÊN tìm thấy trong text.
                                page_lines = footer_texts + page_lines

                        except Exception as exc:
                            print(
                                f"[FOOTER] Trang {page_number}: loi OCR vung "
                                f"tang cuong (bo qua): {exc}"
                            )

                    print(
                        f"[TIME] Trang {page_number} - Footer enhance + OCR: "
                        f"{time.time() - t_footer:.2f}s"
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
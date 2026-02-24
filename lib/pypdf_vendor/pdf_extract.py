#!/usr/bin/env python3
"""Extract text from PDF using vendored pypdf, with OCR fallback for image-based PDFs."""
import os
import sys
import subprocess
import tempfile
import shutil


def extract_with_pypdf(pdf_path: str) -> str:
    """Try text extraction via pypdf."""
    script_dir = os.path.dirname(os.path.abspath(__file__))
    if script_dir not in sys.path:
        sys.path.insert(0, script_dir)

    try:
        from pypdf import PdfReader
    except Exception:
        return ''

    try:
        reader = PdfReader(pdf_path)
    except Exception:
        return ''

    chunks = []
    for page in reader.pages:
        try:
            txt = page.extract_text() or ''
        except Exception:
            txt = ''
        if txt.strip():
            chunks.append(txt)

    return '\n'.join(chunks)


def extract_with_ocr(pdf_path: str, lang: str = 'por+eng') -> str:
    """OCR fallback: convert PDF pages to images via pdftoppm, then run tesseract."""
    if not shutil.which('pdftoppm') or not shutil.which('tesseract'):
        return ''

    tmpdir = tempfile.mkdtemp(prefix='pdfw_ocr_')
    try:
        # Convert PDF pages to PPM images (300 DPI for OCR quality)
        ret = subprocess.run(
            ['pdftoppm', '-r', '300', '-gray', pdf_path, os.path.join(tmpdir, 'page')],
            capture_output=True, timeout=120
        )
        if ret.returncode != 0:
            return ''

        # Find generated page images (sorted)
        images = sorted(
            f for f in os.listdir(tmpdir)
            if f.startswith('page') and (f.endswith('.pgm') or f.endswith('.ppm'))
        )
        if not images:
            return ''

        chunks = []
        for img_file in images:
            img_path = os.path.join(tmpdir, img_file)
            try:
                result = subprocess.run(
                    ['tesseract', img_path, 'stdout', '-l', lang, '--psm', '6'],
                    capture_output=True, text=True, timeout=60
                )
                txt = result.stdout.strip()
                if txt:
                    chunks.append(txt)
            except (subprocess.TimeoutExpired, Exception):
                continue

        return '\n\n'.join(chunks)
    except Exception:
        return ''
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


def main() -> int:
    if len(sys.argv) < 2:
        return 2

    pdf_path = sys.argv[1]
    mode = sys.argv[2] if len(sys.argv) > 2 else 'auto'

    if mode == 'ocr':
        text = extract_with_ocr(pdf_path)
    elif mode == 'text':
        text = extract_with_pypdf(pdf_path)
    else:
        # Auto: try pypdf first, fallback to OCR if result is too short
        text = extract_with_pypdf(pdf_path)
        words = len(text.split()) if text.strip() else 0
        if words < 20:
            ocr_text = extract_with_ocr(pdf_path)
            if len(ocr_text.split()) > words:
                text = ocr_text

    sys.stdout.write(text)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())

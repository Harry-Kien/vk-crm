<?php

use Tests\Support\PdfText;

/**
 * Bộ trích chữ PDF phía test (`Tests\Support\PdfText`) — M9 Task 10.
 *
 * Một luồng nội dung NÉN kết thúc bằng byte `\r` (0x0D) là chuyện xảy ra với khoảng 1/256 luồng, vì
 * dữ liệu nén là byte tuỳ ý. Bản cũ cắt luồng bằng regex `\r?\nendstream` và nuốt luôn byte đó, nên
 * cả trang biến mất khỏi văn bản trích ra — một test mục lục đỏ chập chờn khi mục lục dài hai trang
 * (đo được trong lượt mutation probe Task 10). Test này dựng ĐÚNG ca đó một cách tất định: tìm một
 * nội dung mà bản nén của nó kết thúc bằng `\r`, rồi đặt nó vào một PDF tối thiểu theo đúng cách
 * dompdf ghi (`/Length` trực tiếp, `stream\n…\nendstream`).
 */
function pdfTextMinimalPdf(string $compressed): string
{
    return "%PDF-1.7\n"
        ."1 0 obj\n<< /Type /Page /Resources << /Font << /F1 2 0 R >> >> /Contents 3 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n"
        .'3 0 obj'."\n<< /Length ".strlen($compressed)." /Filter /FlateDecode >>\nstream\n".$compressed."\nendstream\nendobj\n"
        ."%%EOF\n";
}

it('reads a compressed content stream whose last byte is a carriage return', function () {
    $compressed = null;
    $marker = null;

    // Byte cuối của dữ liệu zlib là byte thấp của tổng Adler-32 `a` = (1 + tổng các byte) mod 65521.
    // Thêm từng chữ 'A' (65, nguyên tố cùng nhau với 256) đi qua đủ 256 số dư, nên trong 256 bước
    // chắc chắn có một nội dung mà bản nén kết thúc bằng 0x0D.
    for ($i = 0; $i < 512 && $compressed === null; $i++) {
        $candidate = 'MARKER'.str_repeat('A', $i);
        $bytes = gzcompress("BT /F1 12 Tf ({$candidate}) Tj ET");

        if (str_ends_with($bytes, "\r")) {
            [$compressed, $marker] = [$bytes, $candidate];
        }
    }

    // Tiền đề: thật sự có một luồng như vậy, nếu không thì test không đo gì.
    expect($compressed)->not->toBeNull();

    expect(PdfText::extract(pdfTextMinimalPdf($compressed)))->toContain($marker);
});

it('still reads an ordinary compressed content stream', function () {
    expect(PdfText::extract(pdfTextMinimalPdf(gzcompress('BT /F1 12 Tf (Binh thuong) Tj ET'))))->toContain('Binh thuong');
});

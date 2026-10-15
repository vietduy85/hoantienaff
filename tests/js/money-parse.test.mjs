// Test cho `ccMoneyParseInput` — hàm parse ô nhập tiền DÙNG CHUNG toàn module
// thẻ tín dụng (định nghĩa trong resources/views/credit-card/partials/money-js.blade.php).
//
// Vì partial là Blade (không import được như ES module), test đọc file nguồn và
// tách đúng hàm bằng cách khớp ngoặc nhọn — nhờ vậy nó luôn kiểm CODE THẬT đang
// chạy trên trang, không phải bản sao dễ lệch.
//
// Chạy: `npm run test:js` (hoặc `node --test tests/js`).
//
// Bối cảnh lỗi: trước đây hàm luôn coi dấu phẩy là dấu thập phân nên chuỗi phân
// cách nghìn rất hay gặp "28,005,700" bị đọc thành 28 → người dùng nhập đúng vẫn
// bị coi như sai/thiếu.

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const partial = path.resolve(dir, '../../resources/views/credit-card/partials/money-js.blade.php');

function loadParser(unit) {
    const src = fs.readFileSync(partial, 'utf8');
    const start = src.indexOf('function ccMoneyParseInput(');
    assert.notEqual(start, -1, 'Không tìm thấy ccMoneyParseInput trong money-js.blade.php');

    let i = src.indexOf('{', start);
    let depth = 0;
    let end = -1;
    for (; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) {
                end = i;
                break;
            }
        }
    }
    assert.notEqual(end, -1, 'Không tách được thân hàm ccMoneyParseInput');

    const fnSrc = src.slice(start, end + 1);
    globalThis.window = { ccMoneyUnit: unit };

    return new Function(`${fnSrc}; return ccMoneyParseInput;`)();
}

test('VND: chuỗi phân cách nghìn đọc đúng thành số nguyên', () => {
    const parse = loadParser('VND');

    assert.equal(parse('28005700'), 28005700);
    assert.equal(parse('28,005,700'), 28005700);
    assert.equal(parse('28.005.700'), 28005700);
});

test('VND: dấu thập phân khi trộn hai loại dấu', () => {
    const parse = loadParser('VND');

    // Dấu xuất hiện sau cùng là dấu thập phân.
    assert.equal(parse('1.234,56'), 1234.56);
    assert.equal(parse('1,234.56'), 1234.56);
});

test('VND: một dấu phẩy là thập phân, số 0 hợp lệ', () => {
    const parse = loadParser('VND');

    assert.equal(parse('103,4'), 103.4);
    assert.equal(parse('0'), 0);
    assert.equal(parse('0,5'), 0.5);
});

test('không đọc được ⇒ trả chuỗi rỗng (KHÔNG âm thầm thành 0)', () => {
    const parse = loadParser('VND');

    assert.equal(parse(''), '');
    assert.equal(parse('   '), '');
    assert.equal(parse('abc'), '');
    assert.equal(parse('.'), '');
    assert.equal(parse(','), '');
    assert.equal(parse(null), '');
    assert.equal(parse(undefined), '');
});

test('THOUSAND_VND: đổi nghìn → đồng (giữ hành vi cũ)', () => {
    const parse = loadParser('THOUSAND_VND');

    assert.equal(parse('4.237'), 4237000);
    assert.equal(parse('103,4'), 103400);
    assert.equal(parse('0,1005'), 100.5);
    assert.equal(parse('4237000.5'), 4237000500);
});

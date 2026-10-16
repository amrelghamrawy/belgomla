<?php

declare(strict_types=1);

use App\Support\InvitationMessage;

test('BR-CU08 the default text names the customer, the store and the validity, then the link', function (): void {
    expect(InvitationMessage::render(null, 'مكتبة الأمل', 'مكتبة النور', 7, 'https://alnoor.gomal.test/account/invite/abc'))
        ->toBe("أهلًا مكتبة الأمل، دعاك مكتبة النور للطلب من متجره.\nاضغط الرابط لتحديد كلمة المرور وتفعيل حسابك (صالح 7 أيام):\nhttps://alnoor.gomal.test/account/invite/abc");
});

test('a merchant template fills every placeholder, as often as it appears', function (): void {
    expect(InvitationMessage::render('{اسم_المتجر} يرحب بـ {اسم_العميل} لمدة {عدد_الأيام} يوم — {اسم_المتجر}', 'أحمد', 'النور', 3, 'L'))
        ->toBe("النور يرحب بـ أحمد لمدة 3 يوم — النور\nL");
});

test('the link always goes on its own last line, after trailing blanks are dropped', function (): void {
    expect(InvitationMessage::render("اتفضل سجّل\n\n  ", 'أ', 'ب', 7, 'L'))->toBe("اتفضل سجّل\nL");
});

test('unknown placeholders are listed once each, known ones are not', function (string $template, array $unknown): void {
    expect(InvitationMessage::unknownPlaceholders($template))->toBe($unknown);
})->with([
    'only known' => [InvitationMessage::DEFAULT, []],
    'no braces' => ['أهلًا بيك', []],
    'unknown twice' => ['{الاسم} و {الاسم} و {اسم_العميل} و {link}', ['{الاسم}', '{link}']],
    'empty braces' => ['{}', ['{}']],
]);

test('the default uses only known placeholders and fits the length limit', function (): void {
    expect(InvitationMessage::unknownPlaceholders(InvitationMessage::DEFAULT))->toBe([])
        ->and(mb_strlen(InvitationMessage::DEFAULT))->toBeLessThanOrEqual(InvitationMessage::MAX_LENGTH);
});

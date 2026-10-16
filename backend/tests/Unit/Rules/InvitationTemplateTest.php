<?php

declare(strict_types=1);

use App\Modules\Tenancy\Rules\InvitationTemplate;

function invitationTemplateFailures(mixed $value): array
{
    $failures = [];

    new InvitationTemplate()->validate('invitation_message', $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

test('BR-CU08 a template with known placeholders or none passes', function (mixed $value): void {
    expect(invitationTemplateFailures($value))->toBe([]);
})->with(['known' => ['أهلًا {اسم_العميل} من {اسم_المتجر} ({عدد_الأيام})'], 'plain' => ['أهلًا بيك'], 'not a string is left to the string rule' => [5]]);

test('an unknown placeholder fails with the allowed list in arabic', function (): void {
    expect(invitationTemplateFailures('أهلًا {الاسم} و {link}'))
        ->toBe(['متغير غير معروف: {الاسم}، {link}. المتغيرات المتاحة: {اسم_العميل}، {اسم_المتجر}، {عدد_الأيام}.']);
});

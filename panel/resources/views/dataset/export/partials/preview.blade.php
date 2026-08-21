{{--
    پیش‌نمایش تعداد نمونه‌های منطبق با فیلتر.
    خطوط دستی ساخته می‌شوند تا داخل .readout (که pre-wrap است) دقیقاً همان
    چیزی چاپ شود که جاوااسکریپت هم موقع به‌روزرسانی زنده می‌سازد.
--}}
@php
    $faCount = fn ($value) => \App\Support\DatasetExporter::faDigits(number_format((int) $value));

    $previewLines = [];
    $previewLines[] = '<span class="strong">'.$faCount($preview['total']).' نمونه</span> با این فیلتر انتخاب می‌شود.';

    if ($preview['total'] > $maxSamples) {
        $previewLines[] = '<span class="text-warn">فقط '.$faCount($maxSamples).' نمونهٔ اول وارد بسته می‌شود.</span>';
    }

    if ($preview['total'] > 0) {
        $previewLines[] = 'دارای کادر: '.$faCount($preview['with_boxes']).' • تاییدشده: '.$faCount($preview['verified']);

        foreach ($preview['by_type'] as $previewRow) {
            $previewLines[] = '• '.e($previewRow['label']).': '.$faCount($previewRow['count']);
        }
    } else {
        $previewLines[] = '<span class="text-warn">با این فیلتر نمونه‌ای پیدا نشد؛ بسته ساخته می‌شود ولی خالی خواهد بود.</span>';
    }
@endphp
{!! implode("\n", $previewLines) !!}

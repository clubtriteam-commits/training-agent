<?php
// Общи CSS design tokens за dashboard страниците — извадени тук по UI/UX
// audit finding #1 (2026-08-16). Преди тази промяна dashboard.php изобщо
// не ги ползваше (всеки цвят hardcoded hex, разминат от стойностите
// другаде), а athlete.php/results_comparison.php/lactate_analysis.php
// имаха БУКВАЛНО еднакъв :root блок copy-paste-нат три пъти — лесно да
// се разминат при бъдеща редакция на едно място, не другите.
//
// render_design_tokens() echo-ва само custom property декларациите,
// вътре в собствения :root { ... } на всяка страница — страниците пазят
// своя <style> таг (не е линкван .css файл), за да пасне на функционалния
// includes/*.php патърн в проекта (виж metrics_glossary.php), не въвежда
// нов asset pipeline. Страница-специфични токени (--series-1, --zone-*
// в athlete.php и т.н.) си остават локални, не се местят тук.
//
// --action срещу --series-1/2 (audit finding #2, 2026-08-16): същия
// #2250e3, който вече се ползваше навсякъде hardcoded за връзки/бутони/
// активни състояния — именуван тук, за да е ясно, че UI chrome (действия,
// hover, active) взима --action, а --series-1/2 остават САМО за data
// encoding (графики, mini-viz барове, radar серии). Двете не се сливат
// нарочно — различна роля, не случайно разминаване.
//
// --good/--warn/--bad (+ -soft фон варианти): именуват вече консистентно
// ползваната статус палитра (.lt-badge/.eval-badge/status_badge() вече
// имаха еднакви hex стойности навсякъде — тук просто получават общо име,
// за да не се препечатва #2e7d32 шести път при следващ status компонент).
function render_design_tokens() {
    ?>
    --ink: #0b0b0b;
    --ink-2: #52514e;
    --muted: #898781;
    --grid: #e1e0d9;
    --surface: #ffffff;
    --action: #2250e3;
    --good: #2e7d32;
    --good-soft: #e5f3e6;
    --warn: #b8600a;
    --warn-soft: #fdecd2;
    --bad: #c62828;
    --bad-soft: #fbe2e2;
    <?php
}

// status_badge(): ok/low/high/no_data -> цвят/етикет за ACWR статус.
// Преди дублирана байт-по-байт логика между dashboard.php и athlete.php,
// но с леко разминат inline стил (padding 3px 10px/13px срещу 4px 12px/14px)
// — единна версия, изцяло inline стилизирана (не разчита на .badge клас
// в конкретната страница), затова изглежда еднакво навсякъде без всяка
// страница да пази собствено CSS правило за нея.
function status_badge($status) {
    $colors = ['ok' => '#2e7d32', 'low' => '#f57c00', 'high' => '#c62828', 'no_data' => '#999'];
    $labels = ['ok' => 'Нормално', 'low' => 'Детрениране', 'high' => 'Риск', 'no_data' => 'Няма данни'];
    $color = $colors[$status] ?? '#999';
    $label = $labels[$status] ?? $status;
    return "<span style=\"color:white;padding:4px 12px;border-radius:12px;font-size:14px;vertical-align:middle;background:$color;\">$label</span>";
}

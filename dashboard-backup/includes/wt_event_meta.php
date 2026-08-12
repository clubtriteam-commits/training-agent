<?php
// World Triathlon мета-данни, ръчно снети през WT API-то
// (GET /events/{event_id} и /events/{event_id}/programs/{prog_id}) на
// 2026-08-12, за всички event_id/prog_id, срещани в базата за трите
// следени атлета към тази дата. Споделено между athlete.php и
// results_comparison.php, за да не се разминат бройките между двете
// страници. Статичен списък, НЕ се обновява автоматично — ново
// състезание с нов event_id/prog_id няма да бъде разпознато, докато не
// се допълни ръчно тук (базата/pipeline-ът не пазят тази информация).

// Щафетни (Mixed/Team/Youth Relay) резултати — споделят event_id с
// индивидуалния резултат на атлета от същото състезание, затова
// изглеждат като "дубликат" в списъците (същото заглавие/дата, различно
// време). "event_id:prog_id" -> изключва се навсякъде.
const WT_RELAY_EVENT_PROG_IDS = [
    '172513:582613', // Mixed Junior Relay
    '172516:578766', // Mixed Youth Relay
    '184418:636400', // Mixed Junior Relay
    '184421:634061', // Mixed Junior Relay
    '184435:634346', // Mixed Junior Relay
    '184438:634474', // Mixed Relay
    '184704:635548', // Mixed Relay
    '194266:676125', // Mixed Junior Relay
    '195201:678246', // Mixed Junior Relay
];

function wt_is_relay($event_id, $prog_id) {
    return in_array($event_id . ':' . $prog_id, WT_RELAY_EVENT_PROG_IDS, true);
}

// Дистанция по event_id — от event_specifications[].cat_name. "Sprint" и
// "Super Sprint" НЕ са едно и също (различна дистанция) — умишлено не се
// сливат, за да остане "сравни спринт със спринт" точно, не приблизително.
const WT_EVENT_DISTANCE = [
    172513 => 'Super Sprint',
    172516 => 'Super Sprint',
    172684 => 'Super Sprint',
    172689 => 'Super Sprint',
    172695 => 'Sprint',
    176797 => 'Sprint',
    184418 => 'Super Sprint',
    184421 => 'Super Sprint',
    184430 => 'Super Sprint',
    184435 => 'Super Sprint',
    184438 => 'Super Sprint',
    184520 => 'Sprint',
    184686 => 'Sprint',
    184704 => 'Standard',
    194203 => 'Sprint',
    194260 => 'Sprint',
    194266 => 'Super Sprint',
    194269 => 'Sprint',
    194272 => 'Sprint',
    194273 => 'Sprint',
    194965 => 'Sprint',
    194969 => 'Sprint',
    194973 => 'Sprint',
    194974 => 'Sprint',
    195201 => 'Super Sprint',
    195339 => 'Sprint',
    195382 => 'Sprint',
    195395 => 'Sprint',
    195396 => 'Sprint',
    195430 => 'Sprint',
];

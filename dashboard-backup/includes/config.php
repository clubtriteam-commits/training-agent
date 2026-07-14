<?php
// Определя средата: на сървъра базовата директория е /home/trailser/training-agent,
// локално (Windows/dev) е коренът на репото (две нива над includes/).
function get_base_path() {
    $server_base = '/home/trailser/training-agent';
    if (is_dir($server_base)) {
        return $server_base;
    }
    return dirname(__DIR__, 2);
}

function get_db_path() {
    return get_base_path() . '/data/agent.db';
}

function get_secrets_path() {
    return get_base_path() . '/config/secrets.env';
}

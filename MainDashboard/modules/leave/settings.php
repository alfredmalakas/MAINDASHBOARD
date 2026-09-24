<?php
require_once __DIR__ . '/../../config/session.php';
requireLogin();
redirect('/modules/leave/list.php#leave-settings');

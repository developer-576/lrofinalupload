<?php
// One place to change storage for ALL scripts (front + admin).
if (!defined('LRO_STORAGE_BASE')) {
  define('LRO_STORAGE_BASE', '/home/mfjprqzu/uploads_lrofileupload');
}
if (!is_dir(LRO_STORAGE_BASE)) {
  @mkdir(LRO_STORAGE_BASE, 0755, true);
}

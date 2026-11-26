<?php
/**
 * Plugin Name:       NIBWOZ Better Img
 * Plugin URI:        https://nibwoz.com/
 * Description:       Non-destructive image optimization to WebP, AVIF or the original format, with a background queue and optional delivery.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            Brinsley Aragão / NIBWOZ
 * Author URI:        https://nibwoz.com/sobre/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nibwoz-better-img
 * Domain Path:       /languages
 *
 * @package NIBWOZ\BetterImg
 */

namespace NIBWOZ\BetterImg;

if (!defined('ABSPATH')) { exit; }

const VERSION = '1.3.0';
const FILE = __FILE__;

require_once __DIR__ . '/includes/Core.php';
require_once __DIR__ . '/includes/Diagnostics.php';
require_once __DIR__ . '/includes/Storage.php';
require_once __DIR__ . '/includes/Processor.php';
require_once __DIR__ . '/includes/Metrics.php';
require_once __DIR__ . '/includes/Queue.php';
require_once __DIR__ . '/includes/Delivery.php';
require_once __DIR__ . '/includes/Cleanup.php';
require_once __DIR__ . '/includes/Admin.php';

register_activation_hook(FILE, [Core::class, 'activate']);
register_deactivation_hook(FILE, [Core::class, 'deactivate']);
add_action('init', [Core::class, 'boot'], 1);

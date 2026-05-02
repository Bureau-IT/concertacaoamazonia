<?php
// Disable WP Rocket Lazy Render Content (content-visibility: auto)
add_filter("rocket_lrc_optimization", "__return_false");

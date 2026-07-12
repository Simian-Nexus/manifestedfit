<?php
/**
 * Manifested Fit Blog — child theme functions.
 *
 * Block themes load theme.json automatically; this file adds the brand
 * stylesheet, the hero slider / latest grid / wellness news sections
 * (rendered via shortcodes used inside block templates), and their JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'manifestedfit-fonts',
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Fraunces:opsz,wght@9..144,600;9..144,700&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'manifestedfit-blog',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_script(
		'manifestedfit-blog',
		get_stylesheet_directory_uri() . '/assets/mf-blog.js',
		array(),
		wp_get_theme()->get( 'Version' ),
		true
	);
} );

// Also load the brand extras inside the block editor so drafts preview on-brand.
add_action( 'after_setup_theme', function () {
	add_editor_style( 'style.css' );
	add_theme_support( 'post-thumbnails' );
} );

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function mf_post_category_pill( $post_id ) {
	$cats = get_the_category( $post_id );
	if ( empty( $cats ) ) {
		return '';
	}
	$cat = $cats[0];
	return '<a class="mf-pill" href="' . esc_url( get_category_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
}

function mf_post_byline( $post_id ) {
	$author = get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) );
	$date   = get_the_date( '', $post_id );
	return '<span class="mf-byline">' . esc_html( $author ) . ' &middot; ' . esc_html( $date ) . '</span>';
}

/* -------------------------------------------------------------------------
 * [mf_header] / [mf_footer] — branded chrome shared by every template
 * ---------------------------------------------------------------------- */

function mf_logo_img() {
	return '<img class="mf-logo-img" src="' . esc_url( get_stylesheet_directory_uri() . '/assets/logo.png' ) . '" alt="Manifested Fit" width="51" height="48">';
}

add_shortcode( 'mf_header', function () {
	$home = esc_url( home_url( '/' ) );
	$cats = get_categories( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 4, 'hide_empty' => true ) );
	$nav  = '<a href="' . $home . '">Latest</a>';
	foreach ( $cats as $c ) {
		$nav .= '<a href="' . esc_url( get_category_link( $c ) ) . '">' . esc_html( $c->name ) . '</a>';
	}
	$nav .= '<a href="' . $home . '#wellness-news">News</a>';
	return '<header class="mf-header"><div class="mf-shell mf-header-row">'
		. '<a class="mf-logo" href="' . $home . '">' . mf_logo_img() . '<span class="mf-logo-word">Manifested <em>Fit</em></span></a>'
		. '<nav class="mf-nav" aria-label="Blog">' . $nav . '</nav>'
		. '<a class="mf-btn mf-btn-solid mf-header-cta" href="https://manifestedfit.com/#reset">Free 7-Day Reset</a>'
		. '<button class="mf-nav-toggle" aria-label="Menu">&#9776;</button>'
		. '</div></header>';
} );

add_shortcode( 'mf_footer', function () {
	$home = esc_url( home_url( '/' ) );
	$year = gmdate( 'Y' );
	return '<footer class="mf-footer"><div class="mf-shell mf-footer-grid">'
		. '<div><a class="mf-logo mf-logo-foot" href="' . $home . '">' . mf_logo_img() . '<span class="mf-logo-word">Manifested <em>Fit</em></span></a>'
		. '<p class="mf-footer-tag">Manifest your calm. Move with intention.</p></div>'
		. '<div class="mf-footer-col"><h4>Explore</h4><a href="' . $home . '">Latest posts</a><a href="' . $home . '#wellness-news">Wellness news</a><a href="https://manifestedfit.com/">Main site</a></div>'
		. '<div class="mf-footer-col"><h4>Start here</h4><a href="https://manifestedfit.com/#reset">Free 7-Day Reset</a><a href="https://manifestedfit.com/resources/">Resources</a><a href="https://manifestedfit.com/disclosure/">Disclosure</a></div>'
		. '</div><div class="mf-shell mf-footer-legal">&copy; ' . $year . ' Manifested Fit. Our columnists are editorial voices &mdash; see the about page for how we write.</div></footer>';
} );

/* -------------------------------------------------------------------------
 * [mf_hero_slider] — featured-post carousel with pagination dots
 * ---------------------------------------------------------------------- */

add_shortcode( 'mf_hero_slider', function () {
	$q = new WP_Query( array(
		'posts_per_page'      => 4,
		'post_status'         => 'publish',
		'ignore_sticky_posts' => true,
	) );
	if ( ! $q->have_posts() ) {
		return '';
	}
	$slides = '';
	$dots   = '';
	$i      = 0;
	while ( $q->have_posts() ) {
		$q->the_post();
		$id    = get_the_ID();
		$img   = get_the_post_thumbnail_url( $id, 'full' );
		$style = $img ? ' style="background-image:url(' . esc_url( $img ) . ')"' : '';
		$slides .= '<article class="mf-slide' . ( 0 === $i ? ' is-active' : '' ) . '"' . $style . '>'
			. '<div class="mf-slide-scrim"></div>'
			. '<div class="mf-slide-inner">'
			. mf_post_category_pill( $id )
			. '<h2 class="mf-slide-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h2>'
			. '<p class="mf-slide-excerpt">' . esc_html( wp_trim_words( get_the_excerpt(), 24 ) ) . '</p>'
			. '<div class="mf-slide-meta">' . mf_post_byline( $id )
			. '<a class="mf-btn mf-btn-light" href="' . esc_url( get_permalink() ) . '">Read the post</a></div>'
			. '</div></article>';
		$dots .= '<button class="mf-dot' . ( 0 === $i ? ' is-active' : '' ) . '" data-slide="' . $i . '" aria-label="Go to slide ' . ( $i + 1 ) . '"></button>';
		$i++;
	}
	wp_reset_postdata();
	return '<section class="mf-hero" aria-label="Featured posts">'
		. '<div class="mf-slides">' . $slides . '</div>'
		. '<button class="mf-arrow mf-prev" aria-label="Previous slide">&#8249;</button>'
		. '<button class="mf-arrow mf-next" aria-label="Next slide">&#8250;</button>'
		. '<div class="mf-dots">' . $dots . '</div>'
		. '</section>';
} );

/* -------------------------------------------------------------------------
 * [mf_latest_grid] — card grid of recent posts (skips the hero's newest post
 * only when offset is passed)
 * ---------------------------------------------------------------------- */

add_shortcode( 'mf_latest_grid', function ( $atts ) {
	$atts = shortcode_atts( array( 'count' => 6, 'offset' => 0 ), $atts, 'mf_latest_grid' );
	$q = new WP_Query( array(
		'posts_per_page'      => (int) $atts['count'],
		'offset'              => (int) $atts['offset'],
		'post_status'         => 'publish',
		'ignore_sticky_posts' => true,
	) );
	if ( ! $q->have_posts() ) {
		return '';
	}
	$cards = '';
	while ( $q->have_posts() ) {
		$q->the_post();
		$id  = get_the_ID();
		$img = get_the_post_thumbnail_url( $id, 'large' );
		$media = $img
			? '<a class="mf-card-media" href="' . esc_url( get_permalink() ) . '"><img src="' . esc_url( $img ) . '" alt="" loading="lazy"></a>'
			: '<a class="mf-card-media mf-card-media-empty" href="' . esc_url( get_permalink() ) . '"></a>';
		$cards .= '<article class="mf-card">' . $media
			. '<div class="mf-card-body">' . mf_post_category_pill( $id )
			. '<h3 class="mf-card-title"><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>'
			. '<p class="mf-card-excerpt">' . esc_html( wp_trim_words( get_the_excerpt(), 20 ) ) . '</p>'
			. '<div class="mf-card-meta">' . mf_post_byline( $id ) . '</div>'
			. '</div></article>';
	}
	wp_reset_postdata();
	return '<div class="mf-grid">' . $cards . '</div>';
} );

/* -------------------------------------------------------------------------
 * [mf_wellness_news] — external wellness headlines from Google News RSS.
 * Merged from several queries, cached, links open in a new tab.
 * ---------------------------------------------------------------------- */

add_shortcode( 'mf_wellness_news', function ( $atts ) {
	$atts   = shortcode_atts( array( 'count' => 6 ), $atts, 'mf_wellness_news' );
	$count  = (int) $atts['count'];
	$cached = get_transient( 'mf_wellness_news' );
	if ( false === $cached ) {
		include_once ABSPATH . WPINC . '/feed.php';
		$queries = array( 'wellness', 'mindfulness', 'healthy habits', 'mental health tips' );
		$items   = array();
		foreach ( $queries as $query ) {
			$feed = fetch_feed( 'https://news.google.com/rss/search?q=' . rawurlencode( $query ) . '&hl=en-CA&gl=CA&ceid=CA:en' );
			if ( is_wp_error( $feed ) ) {
				continue;
			}
			foreach ( $feed->get_items( 0, 5 ) as $item ) {
				$title = html_entity_decode( $item->get_title(), ENT_QUOTES );
				// Google News titles end with " - Source"; split it out.
				$source = '';
				if ( preg_match( '/^(.*) - ([^-]+)$/', $title, $m ) ) {
					$title  = $m[1];
					$source = $m[2];
				}
				$items[ md5( $title ) ] = array(
					'hash'   => md5( $title ),
					'title'  => $title,
					'source' => $source,
					'link'   => $item->get_permalink(),
					'time'   => (int) $item->get_date( 'U' ),
				);
			}
		}
		$items = array_values( $items );
		usort( $items, function ( $a, $b ) { return $b['time'] - $a['time']; } );
		$cached = array_slice( $items, 0, 12 );
		set_transient( 'mf_wellness_news', $cached, 3 * HOUR_IN_SECONDS );
		// Let the content engine queue these headlines for AI rewrites.
		do_action( 'mf_wellness_news_fetched', $cached );
	}
	if ( empty( $cached ) ) {
		return '';
	}
	// Headlines the engine has already rewritten AND published link to our
	// own post instead of the external article.
	$ours = array();
	if ( class_exists( 'MFCE_Engine' ) && method_exists( 'MFCE_Engine', 'news_post_map' ) ) {
		$hashes = array();
		foreach ( $cached as $n ) {
			$hashes[] = isset( $n['hash'] ) ? $n['hash'] : md5( $n['title'] );
		}
		$ours = MFCE_Engine::news_post_map( $hashes );
	}
	$out = '';
	foreach ( array_slice( $cached, 0, $count ) as $n ) {
		$hash = isset( $n['hash'] ) ? $n['hash'] : md5( $n['title'] );
		if ( isset( $ours[ $hash ] ) ) {
			$out .= '<a class="mf-news-card mf-news-card-ours" href="' . esc_url( $ours[ $hash ] ) . '">'
				. '<span class="mf-news-source">Our take &middot; via ' . esc_html( $n['source'] ? $n['source'] : 'the web' ) . '</span>'
				. '<span class="mf-news-title">' . esc_html( $n['title'] ) . '</span>'
				. '<span class="mf-news-time">Read it on Manifested Fit &rarr;</span>'
				. '</a>';
			continue;
		}
		$ago = $n['time'] ? human_time_diff( $n['time'] ) . ' ago' : '';
		$out .= '<a class="mf-news-card" href="' . esc_url( $n['link'] ) . '" target="_blank" rel="noopener nofollow">'
			. '<span class="mf-news-source">' . esc_html( $n['source'] ? $n['source'] : 'Wellness news' ) . '</span>'
			. '<span class="mf-news-title">' . esc_html( $n['title'] ) . '</span>'
			. '<span class="mf-news-time">' . esc_html( $ago ) . ' &nearr;</span>'
			. '</a>';
	}
	return '<div class="mf-news-grid">' . $out . '</div>';
} );

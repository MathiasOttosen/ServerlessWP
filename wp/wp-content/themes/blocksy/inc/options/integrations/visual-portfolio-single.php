<?php

$prefix = 'vs_portfolio_single_';

$options = [
	'vs_portfolio_single_options' => [
		'type' => 'ct-options',
		'inner-options' => [
			blocksy_get_options('general/page-title', [
				'prefix' => 'vs_portfolio_single',
				'is_single' => true,
				'is_cpt' => true,
				'enabled_label' => blocksy_safe_sprintf(
					__('%s Title', 'blocksy'),
					'Portfolio'
				),
				'location_name' => __('Portfolio Single', 'blocksy')
			]),

			blocksy_rand_md5() => [
				'type' => 'ct-title',
				'label' => __( 'Portfolio Structure', 'blocksy' ),
			],

			blocksy_rand_md5() => [
				'title' => __( 'General', 'blocksy' ),
				'type' => 'tab',
				'options' => [
					blocksy_get_options('single-elements/structure', [
						'default_structure' => 'type-4',
						'prefix' => 'vs_portfolio_single',
					]),
				],
			],

			blocksy_rand_md5() => [
				'title' => __( 'Design', 'blocksy' ),
				'type' => 'tab',
				'options' => [

					blocksy_get_options('single-elements/structure-design', [
						'prefix' => 'vs_portfolio_single',
					])

				],
			],

		]
	]
];


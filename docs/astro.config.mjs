// @ts-check
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightThemeVintage from 'starlight-theme-vintage';

// The TextMate grammar the editor extensions ship, read straight from
// `editors/` so the site highlights rule text the same way an editor does and
// there is one definition of the language's shape rather than two. Shiki takes
// the language id from `name`, so it is lower-cased here for ```warrant fences.
const warrantGrammar = {
	...JSON.parse(
		readFileSync(
			fileURLToPath(new URL('../editors/vscode/syntaxes/warrant.tmLanguage.json', import.meta.url)),
			'utf8',
		),
	),
	name: 'warrant',
};

// https://astro.build/config
export default defineConfig({
	// The live domain — enables correct canonical URLs and the sitemap.
	site: 'https://laravel-warrant.dev',
	// Every page that moved in the docs restructure keeps its old URL working.
	redirects: {
		'/guides/schemas/': '/schemas/anatomy/',
		'/guides/conditions/': '/schemas/conditions/',
		'/guides/rule-language/': '/rules/basics/',
		'/guides/rule-builder/': '/rules/builder/',
		'/guides/rule-templates/': '/rules/templates/',
		'/guides/cross-schema-checks/': '/rules/references/',
		'/guides/grants-and-denials/': '/concepts/grants-and-denials/',
		'/guides/resolvers/': '/supplying-rules/resolver/',
		'/guides/checking-access/': '/checking/ways-in/',
		'/guides/denial-messages/': '/rules/denial-messages/',
		'/guides/reachability/': '/concepts/reachability/',
		'/guides/context/': '/concepts/context/',
		'/guides/middleware/': '/checking/middleware/',
		'/guides/how-it-compiles/': '/sql/rule-to-query/',
		'/guides/testing/': '/testing/rules/',
		'/reference/checking-api/': '/reference/warrant-facade/',
		'/reference/schema-api/': '/reference/warrant-schema/',
		'/reference/rule-building-api/': '/reference/warrant-rule-set/',
		'/reference/middleware-api/': '/reference/middleware/',
		'/reference/errors/': '/diagnosis/errors/',
		'/reference/api-cheat-sheet/': '/reference/cheat-sheet/',
	},
	integrations: [
		starlight({
			// Shown in the top-left masthead and used as the base for <title> tags.
			title: 'Laravel Warrant',
			// 3D shield-and-lock mark rendered to the left of the title wordmark.
			logo: { src: './src/assets/warrant-logo.png', alt: 'Laravel Warrant' },
			// Browser-tab icon (overrides Starlight's default /favicon.svg).
			favicon: '/favicon.png',
			// Extra icon for iOS home-screen / bookmarks, plus the social-share
			// (Open Graph / Twitter) image used site-wide when a page is linked.
			head: [
				{
					tag: 'link',
					attrs: { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' },
				},
				{
					tag: 'meta',
					attrs: { property: 'og:image', content: 'https://laravel-warrant.dev/og-image.png?v=2' },
				},
				{
					tag: 'meta',
					attrs: { property: 'og:image:width', content: '1200' },
				},
				{
					tag: 'meta',
					attrs: { property: 'og:image:height', content: '630' },
				},
				{
					tag: 'meta',
					attrs: { name: 'twitter:image', content: 'https://laravel-warrant.dev/og-image.png?v=2' },
				},
			],
			// Site-wide default meta description for social/search (per-page
			// frontmatter `description` overrides this).
			description:
				'Schema-based permissions and authorization for Laravel — write row-level access rules once and compile them straight to SQL.',
			// Repo link rendered as an icon in the top-right header.
			social: [
				{
					icon: 'github',
					label: 'GitHub',
					href: 'https://github.com/patrickjames242/laravel-warrant',
				},
			],
			// Register the Warrant rule language with Expressive Code's Shiki
			// instance, so a ```warrant fence is highlighted like any other language.
			expressiveCode: {
				shiki: { langs: [warrantGrammar] },
			},
			// starlight-theme-vintage: styled after the timeless legacy Astro docs —
			// warm, editorial, with its own Expressive Code themes and palette.
			plugins: [starlightThemeVintage()],
			// Dark-only site: force the dark theme and drop the light/dark toggle.
			components: {
				ThemeProvider: './src/components/ThemeProvider.astro',
				ThemeSelect: './src/components/ThemeSelect.astro',
			},
			// Our own overrides (accent colour, small tweaks) layered on top of the theme.
			customCss: ['./src/styles/custom.css'],
			// Show a "next / previous page" pager and the editable-on-GitHub link.
			editLink: {
				baseUrl:
					'https://github.com/patrickjames242/laravel-warrant/edit/warrant-rename/docs/',
			},
			// autogenerate builds each sidebar group from the files in a directory.
			// Ordering within a group is controlled per-page via frontmatter
			// `sidebar.order`; the group `label` here is what the reader sees.
			// (Starlight >=0.39 requires the autogenerate config to sit inside an
			// `items` array rather than alongside the label directly.)
			// Four sections, each holding the groups that belong to it. The sections
			// stay open so the shape of the docs is visible at a glance; every group
			// inside them starts collapsed, and `collapsed` on the autogenerate config
			// does the same for a group a subdirectory produces.
			sidebar: [
				{
					label: 'Start here',
					items: [
						{
							label: 'Introduction',
							collapsed: true,
							items: [{ autogenerate: { directory: 'getting-started', collapsed: true } }],
						},
						{
							label: 'Your first rule',
							collapsed: true,
							items: [{ autogenerate: { directory: 'first-rule', collapsed: true } }],
						},
					],
				},
				{
					label: 'Guides',
					items: [
						{
							// Spelled out rather than autogenerated so the nested Context
							// group carries a proper label; autogenerate would name it
							// after the directory, in lower case.
							label: 'Concepts',
							collapsed: true,
							items: [
								'concepts/rules-compile-to-sql',
								'concepts/rules-and-abilities',
								'concepts/row-identity',
								'concepts/three-truth-values',
								'concepts/grants-and-denials',
								'concepts/how-a-decision-is-made',
								{
									label: 'Context',
									collapsed: true,
									items: [{ autogenerate: { directory: 'concepts/context', collapsed: true } }],
								},
								'concepts/frames',
								'concepts/reachability',
								'concepts/schemas-as-vocabulary',
								'concepts/what-rules-cannot-do',
							],
						},
						{
							label: 'Writing rules',
							collapsed: true,
							items: [{ autogenerate: { directory: 'rules', collapsed: true } }],
						},
						{
							label: 'Defining schemas',
							collapsed: true,
							items: [{ autogenerate: { directory: 'schemas', collapsed: true } }],
						},
						{
							label: 'Supplying rules',
							collapsed: true,
							items: [{ autogenerate: { directory: 'supplying-rules', collapsed: true } }],
						},
						{
							label: 'Asking questions',
							collapsed: true,
							items: [{ autogenerate: { directory: 'checking', collapsed: true } }],
						},
						{
							label: 'Recipes',
							collapsed: true,
							items: [{ autogenerate: { directory: 'recipes', collapsed: true } }],
						},
					],
				},
				{
					label: 'Going deeper',
					items: [
						{
							label: 'Understanding the SQL',
							collapsed: true,
							items: [{ autogenerate: { directory: 'sql', collapsed: true } }],
						},
						{
							label: 'Testing',
							collapsed: true,
							items: [{ autogenerate: { directory: 'testing', collapsed: true } }],
						},
						{
							label: 'Running it in production',
							collapsed: true,
							items: [{ autogenerate: { directory: 'production', collapsed: true } }],
						},
						{
							label: 'Editor tooling',
							collapsed: true,
							items: [{ autogenerate: { directory: 'editors', collapsed: true } }],
						},
						{
							label: "When something's wrong",
							collapsed: true,
							items: [{ autogenerate: { directory: 'diagnosis', collapsed: true } }],
						},
					],
				},
				{
					label: 'Reference',
					items: [
						{
							label: 'API reference',
							collapsed: true,
							items: [{ autogenerate: { directory: 'reference', collapsed: true } }],
						},
						{
							label: 'Roadmap',
							collapsed: true,
							items: [{ autogenerate: { directory: 'roadmap', collapsed: true } }],
						},
					],
				},
			],
		}),
	],
});

// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightThemeVintage from 'starlight-theme-vintage';

// https://astro.build/config
export default defineConfig({
	// The live domain — enables correct canonical URLs and the sitemap.
	site: 'https://laravel-warrant.dev',
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
			sidebar: [
				{ label: 'Getting Started', items: [{ autogenerate: { directory: 'getting-started' } }] },
				{ label: 'Guides', items: [{ autogenerate: { directory: 'guides' } }] },
				{ label: 'API Reference', items: [{ autogenerate: { directory: 'reference' } }] },
			],
		}),
	],
});

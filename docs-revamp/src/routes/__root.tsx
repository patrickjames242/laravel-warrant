import { Outlet, createRootRoute } from '@tanstack/react-router'
import { SiteFooter } from '../components/SiteFooter'
import { SiteHeader } from '../components/SiteHeader'

export const Route = createRootRoute({
  component: RootLayout,
})

function RootLayout() {
  return (
    <div className="min-h-screen overflow-x-clip bg-ink">
      <SiteHeader />
      <Outlet />
      <SiteFooter />
    </div>
  )
}

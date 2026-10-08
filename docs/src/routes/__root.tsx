import { Outlet, createRootRoute } from '@tanstack/react-router'
import { LoadingLine } from '../components/LoadingLine'

export const Route = createRootRoute({
  component: RootLayout,
})

function RootLayout() {
  return (
    <div className="min-h-screen overflow-x-clip bg-ink">
      <Outlet />
      <LoadingLine />
    </div>
  )
}

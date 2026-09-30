import { createFileRoute } from '@tanstack/react-router'
import { useEffect } from 'react'
import { SiteFooter } from '../components/SiteFooter'
import { SiteHeader } from '../components/SiteHeader'
import { CallToAction } from '../components/home/CallToAction'
import { Compare } from '../components/home/Compare'
import { Fit } from '../components/home/Fit'
import { FlowBridge } from '../components/home/FlowBridge'
import { Hero } from '../components/home/Hero'
import { MentalModel } from '../components/home/MentalModel'

export const Route = createFileRoute('/')({
  component: HomePage,
})

function HomePage() {
  useEffect(() => {
    document.title = 'Laravel Warrant · Row-level authorization for Laravel'
  }, [])

  return (
    <>
      <SiteHeader />
      <main>
        <div className="relative">
          <Hero />
          <MentalModel />
          <FlowBridge />
        </div>
        <Compare />
        <Fit />
        <CallToAction />
      </main>
      <SiteFooter />
    </>
  )
}

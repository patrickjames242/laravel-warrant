import { createFileRoute } from '@tanstack/react-router'
import { useEffect, useRef } from 'react'
import { SiteFooter } from '../components/SiteFooter'
import { SiteHeader } from '../components/SiteHeader'
import { CallToAction } from '../components/home/CallToAction'
import { Compare } from '../components/home/Compare'
import { Fit } from '../components/home/Fit'
import { FlowBridge } from '../components/home/FlowBridge'
import { Hero } from '../components/home/Hero'
import { MentalModel } from '../components/home/MentalModel'
import { useHeroIntro } from '../components/home/useHeroIntro'

export const Route = createFileRoute('/')({
  component: HomePage,
})

function HomePage() {
  const flow = useRef<HTMLDivElement>(null)
  useHeroIntro(flow)

  useEffect(() => {
    document.title = 'Laravel Warrant · Row-level authorization for Laravel'
  }, [])

  return (
    <>
      <SiteHeader />
      <main>
        <div ref={flow} className="relative">
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

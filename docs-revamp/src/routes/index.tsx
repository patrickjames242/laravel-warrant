import { createFileRoute } from '@tanstack/react-router'
import { CallToAction } from '../components/home/CallToAction'
import { Compare } from '../components/home/Compare'
import { Fit } from '../components/home/Fit'
import { Hero } from '../components/home/Hero'
import { MentalModel } from '../components/home/MentalModel'

export const Route = createFileRoute('/')({
  component: HomePage,
})

function HomePage() {
  return (
    <main>
      <Hero />
      <MentalModel />
      <Compare />
      <Fit />
      <CallToAction />
    </main>
  )
}

import { Header } from '@/components/layout/header'
import { prisma } from '@/lib/db'
import Link from 'next/link'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Labs' }

const difficultyColor: Record<string, string> = {
  EASY: 'badge-green', MEDIUM: 'badge-yellow', HARD: 'badge-red', EXPERT: 'badge-red',
}

export default async function LabsPage() {
  const labs = await prisma.lab.findMany({ orderBy: { createdAt: 'desc' } })

  return (
    <div>
      <Header title="Exploitation Labs" description="Containerized CTF-style labs with real CVEs and real shells." />
      {labs.length === 0 ? (
        <div className="card p-12 text-center">
          <div className="text-3xl mb-3">⚗️</div>
          <div className="font-medium text-ink mb-1">Labs coming soon</div>
          <p className="text-sm text-muted">New labs are added weekly. Check back soon.</p>
        </div>
      ) : (
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {labs.map((lab) => (
            <Link key={lab.id} href={`/labs/${lab.slug}`} className="card p-5 hover:border-accent/40 transition-all group">
              <div className="flex items-start justify-between mb-3">
                <span className={difficultyColor[lab.difficulty] ?? 'badge-gray'}>{lab.difficulty}</span>
                {lab.isPro && <span className="badge-blue text-[10px]">PRO</span>}
              </div>
              <div className="font-medium text-ink group-hover:text-accent transition-colors mb-1">{lab.title}</div>
              <p className="text-xs text-dim line-clamp-2">{lab.description}</p>
              <div className="flex items-center justify-between mt-3">
                <span className="text-xs text-muted font-mono">{lab.category}</span>
                <span className="text-xs text-warning font-medium">+{lab.points} XP</span>
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

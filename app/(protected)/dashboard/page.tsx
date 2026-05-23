import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'
import { prisma } from '@/lib/db'
import { Header } from '@/components/layout/header'
import { formatRelative, scoreToGrade, xpProgress } from '@/lib/utils'
import Link from 'next/link'
import { Shield, FlaskConical, BookOpen, Trophy, ArrowRight, Zap } from 'lucide-react'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Dashboard' }

export default async function DashboardPage() {
  const session = await getServerSession(authOptions)!
  const userId = session!.user.id

  const [scans, badges, recentScans, topUsers] = await Promise.all([
    prisma.scan.count({ where: { userId } }),
    prisma.userBadge.count({ where: { userId } }),
    prisma.scan.findMany({ where: { userId }, orderBy: { createdAt: 'desc' }, take: 5 }),
    prisma.user.findMany({ orderBy: { xp: 'desc' }, take: 5, select: { id: true, username: true, xp: true, level: true } }),
  ])

  const prog = xpProgress(session!.user.xp ?? 0)

  const stats = [
    { label: 'Total Scans', value: scans,                                  icon: Shield,    color: 'text-accent',   bg: 'bg-accent/10' },
    { label: 'XP Earned',   value: (session!.user.xp ?? 0).toLocaleString(), icon: Zap,       color: 'text-warning',  bg: 'bg-warning/10' },
    { label: 'Badges',      value: badges,                                 icon: Trophy,    color: 'text-success',  bg: 'bg-success/10' },
    { label: 'Level',       value: prog.level,                             icon: ArrowRight, color: 'text-accent-2', bg: 'bg-accent-2/10' },
  ]

  return (
    <div>
      <Header title={`Welcome back, ${session!.user.username ?? session!.user.name} 👋`}
        description="Here's what's happening with your security training." />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        {stats.map((s) => {
          const Icon = s.icon
          return (
            <div key={s.label} className="stat-card">
              <div className={`w-9 h-9 rounded-lg ${s.bg} flex items-center justify-center mb-3`}>
                <Icon size={16} className={s.color} />
              </div>
              <div className="text-2xl font-bold text-ink font-mono">{s.value}</div>
              <div className="text-xs text-muted">{s.label}</div>
            </div>
          )
        })}
      </div>

      <div className="grid lg:grid-cols-3 gap-4">
        <div className="card p-5">
          <div className="section-title">XP Progress</div>
          <div className="flex items-end justify-between mb-3">
            <div>
              <div className="text-3xl font-bold font-mono text-ink">{prog.level}</div>
              <div className="text-xs text-muted">Current level</div>
            </div>
            <div className="text-right">
              <div className="text-sm font-mono text-dim">{prog.xp} / {prog.next} XP</div>
              <div className="text-xs text-muted">{prog.pct}% to next level</div>
            </div>
          </div>
          <div className="h-2 bg-border-2 rounded-full overflow-hidden">
            <div className="h-full bg-gradient-to-r from-accent to-accent-2 rounded-full transition-all" style={{ width: `${prog.pct}%` }} />
          </div>
        </div>

        <div className="card p-5">
          <div className="section-title">Quick Actions</div>
          <div className="space-y-2">
            {[
              { href: '/scanner', label: 'Run Security Scan', icon: Shield, desc: 'Scan a target for vulnerabilities' },
              { href: '/labs', label: 'Browse Labs', icon: FlaskConical, desc: 'Practice exploitation skills' },
              { href: '/quiz', label: 'Take a Quiz', icon: BookOpen, desc: 'Test your CEH knowledge' },
            ].map((a) => {
              const Icon = a.icon
              return (
                <Link key={a.href} href={a.href} className="flex items-center gap-3 p-3 rounded-lg hover:bg-surface-2 transition-colors group">
                  <div className="w-8 h-8 bg-accent/10 rounded-lg flex items-center justify-center flex-shrink-0">
                    <Icon size={14} className="text-accent" />
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm font-medium text-ink">{a.label}</div>
                    <div className="text-xs text-muted truncate">{a.desc}</div>
                  </div>
                  <ArrowRight size={13} className="ml-auto text-muted opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0" />
                </Link>
              )
            })}
          </div>
        </div>

        <div className="card p-5">
          <div className="flex items-center justify-between mb-3">
            <div className="section-title mb-0">Top Operators</div>
            <Link href="/leaderboard" className="text-xs text-accent hover:underline">View all →</Link>
          </div>
          <div className="space-y-2">
            {topUsers.map((u, i) => (
              <div key={u.id} className="flex items-center gap-3">
                <div className="w-6 text-center text-xs font-mono text-muted">{i + 1}</div>
                <div className="w-7 h-7 bg-accent/20 rounded-full flex items-center justify-center text-xs font-bold text-accent flex-shrink-0">
                  {u.username.charAt(0).toUpperCase()}
                </div>
                <div className="flex-1 min-w-0">
                  <div className="text-sm text-ink truncate font-medium">{u.username}</div>
                  <div className="text-xs text-muted">Lv.{u.level}</div>
                </div>
                <div className="text-xs font-mono text-dim">{u.xp.toLocaleString()} XP</div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {recentScans.length > 0 && (
        <div className="card mt-4">
          <div className="flex items-center justify-between px-5 py-4 border-b border-border">
            <div className="font-medium text-sm text-ink">Recent Scans</div>
            <Link href="/scanner/history" className="text-xs text-accent hover:underline">View all →</Link>
          </div>
          <div className="divide-y divide-border">
            {recentScans.map((scan) => {
              const { grade, color } = scoreToGrade(scan.score ?? 0)
              return (
                <div key={scan.id} className="flex items-center gap-4 px-5 py-3">
                  <div className={`w-10 h-10 rounded-lg bg-surface-2 flex items-center justify-center font-bold font-mono text-sm ${color} flex-shrink-0`}>
                    {scan.score !== null ? grade : '—'}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium text-ink truncate font-mono">{scan.targetUrl}</div>
                    <div className="text-xs text-muted">{formatRelative(scan.createdAt)} · {scan.profile}</div>
                  </div>
                  <div className={`badge ${scan.status === 'DONE' ? 'badge-green' : scan.status === 'ERROR' ? 'badge-red' : 'badge-gray'}`}>
                    {scan.status.toLowerCase()}
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}

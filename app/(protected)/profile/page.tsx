import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'
import { prisma } from '@/lib/db'
import { Header } from '@/components/layout/header'
import { formatDate, xpProgress } from '@/lib/utils'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Profile' }

export default async function ProfilePage() {
  const session = await getServerSession(authOptions)!
  const user = await prisma.user.findUnique({
    where: { id: session!.user.id },
    include: {
      badges: { include: { badge: true }, orderBy: { earnedAt: 'desc' } },
      _count: { select: { scans: true, labAttempts: true, quizAttempts: true } },
    },
  })
  if (!user) return null

  const prog = xpProgress(user.xp)

  return (
    <div>
      <Header title="Profile" />
      <div className="grid lg:grid-cols-[300px_1fr] gap-6">
        {/* Left: user card */}
        <div className="space-y-4">
          <div className="card p-6 text-center">
            <div className="w-20 h-20 bg-accent/20 rounded-full flex items-center justify-center mx-auto mb-4">
              <span className="text-3xl font-bold text-accent">
                {user.username.charAt(0).toUpperCase()}
              </span>
            </div>
            <div className="text-lg font-bold text-ink">{user.name ?? user.username}</div>
            <div className="text-sm text-muted font-mono">@{user.username}</div>
            <div className="flex items-center justify-center gap-2 mt-2">
              <span className={user.plan === 'PRO' ? 'badge-blue' : 'badge-gray'}>{user.plan}</span>
              {user.role === 'ADMIN' && <span className="badge-red">ADMIN</span>}
            </div>
            <div className="mt-4 text-xs text-muted">Member since {formatDate(user.createdAt)}</div>
          </div>

          <div className="card p-5">
            <div className="section-title">XP &amp; Level</div>
            <div className="flex items-end justify-between mb-2">
              <div className="text-4xl font-bold font-mono text-ink">{prog.level}</div>
              <div className="text-xs text-muted text-right">
                <div className="font-mono text-dim">{prog.xp} XP total</div>
                <div>{prog.pct}% to Lv.{prog.level + 1}</div>
              </div>
            </div>
            <div className="h-2 bg-border-2 rounded-full overflow-hidden">
              <div className="h-full bg-gradient-to-r from-accent to-accent-2 rounded-full" style={{ width: `${prog.pct}%` }} />
            </div>
          </div>

          <div className="card p-5">
            <div className="section-title">Stats</div>
            <div className="space-y-3">
              {[
                ['Scans Run', user._count.scans],
                ['Labs Attempted', user._count.labAttempts],
                ['Quiz Attempts', user._count.quizAttempts],
                ['Current Streak', `${user.streakDays}d 🔥`],
                ['Longest Streak', `${user.longestStreak}d`],
              ].map(([k, v]) => (
                <div key={String(k)} className="flex justify-between items-center">
                  <span className="text-sm text-muted">{k}</span>
                  <span className="text-sm font-medium font-mono text-ink">{v}</span>
                </div>
              ))}
            </div>
          </div>
        </div>

        {/* Right: badges */}
        <div>
          <div className="card p-5">
            <div className="section-title">Badges ({user.badges.length})</div>
            {user.badges.length === 0 ? (
              <p className="text-sm text-muted">No badges yet. Complete challenges to earn badges.</p>
            ) : (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                {user.badges.map(({ badge, earnedAt }) => (
                  <div key={badge.id} className="card-2 p-4 text-center">
                    <div className="text-3xl mb-2">{badge.icon}</div>
                    <div className="text-sm font-medium text-ink">{badge.name}</div>
                    <div className="text-xs text-muted mt-0.5">{badge.description}</div>
                    <div className="text-[10px] text-muted/60 mt-1 font-mono">{formatDate(earnedAt)}</div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'
import { prisma } from '@/lib/db'
import { Header } from '@/components/layout/header'
import { xpProgress } from '@/lib/utils'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Leaderboard' }

export default async function LeaderboardPage() {
  const session = await getServerSession(authOptions)
  const users = await prisma.user.findMany({
    orderBy: { xp: 'desc' },
    take: 50,
    select: { id: true, username: true, xp: true, level: true, streakDays: true, plan: true,
      _count: { select: { scans: true, labAttempts: true } } },
  })
  const medals = ['🥇', '🥈', '🥉']

  return (
    <div>
      <Header title="Leaderboard" description="Top operators ranked by XP." />
      <div className="card">
        <div className="px-5 py-3 border-b border-border grid grid-cols-[2rem_1fr_6rem_6rem_6rem_5rem] gap-4 text-xs text-muted uppercase tracking-wide">
          <span>#</span><span>Operator</span>
          <span className="text-right">XP</span><span className="text-right">Level</span>
          <span className="text-right">Scans</span><span className="text-right">Plan</span>
        </div>
        <div className="divide-y divide-border">
          {users.map((user, i) => {
            const isMe = user.id === session?.user.id
            return (
              <div key={user.id} className={`px-5 py-3 grid grid-cols-[2rem_1fr_6rem_6rem_6rem_5rem] gap-4 items-center ${isMe ? 'bg-accent/5 border-l-2 border-accent' : ''}`}>
                <div className="text-sm font-mono text-center">{i < 3 ? medals[i] : <span className="text-muted">{i + 1}</span>}</div>
                <div className="flex items-center gap-3 min-w-0">
                  <div className="w-8 h-8 rounded-full bg-accent/20 flex items-center justify-center text-accent text-sm font-bold flex-shrink-0">
                    {user.username.charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <div className="text-sm font-medium text-ink truncate">{user.username} {isMe && <span className="text-xs text-accent">(you)</span>}</div>
                    {user.streakDays > 0 && <div className="text-xs text-warning">🔥 {user.streakDays}d streak</div>}
                  </div>
                </div>
                <div className="text-right font-mono text-sm text-ink">{user.xp.toLocaleString()}</div>
                <div className="text-right"><span className="font-mono text-sm text-dim">Lv.{user.level}</span></div>
                <div className="text-right font-mono text-sm text-muted">{user._count.scans}</div>
                <div className="text-right"><span className={user.plan === 'PRO' ? 'badge-blue text-xs' : 'text-xs text-muted'}>{user.plan}</span></div>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}

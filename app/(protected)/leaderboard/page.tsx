import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'
import { prisma } from '@/lib/db'
import { Header } from '@/components/layout/header'
import { xpProgress } from '@/lib/utils'
import { Trophy, Flame, Shield, FlaskConical, Zap } from 'lucide-react'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Leaderboard' }

const MEDAL = ['🥇', '🥈', '🥉'] as const

const PODIUM_STYLES = {
  1: {
    order: 'order-2',
    height: 'h-44',
    barColor: 'from-warning/80 to-warning/30',
    ring: 'ring-2 ring-warning/50',
    avatarBg: 'bg-warning/20',
    avatarText: 'text-warning',
    label: 'text-warning',
    glow: 'shadow-[0_0_32px_rgba(245,158,11,0.25)]',
    rankNum: 'text-warning',
  },
  2: {
    order: 'order-1',
    height: 'h-32',
    barColor: 'from-dim/60 to-dim/20',
    ring: 'ring-2 ring-dim/30',
    avatarBg: 'bg-dim/15',
    avatarText: 'text-dim',
    label: 'text-dim',
    glow: '',
    rankNum: 'text-dim',
  },
  3: {
    order: 'order-3',
    height: 'h-24',
    barColor: 'from-amber-700/60 to-amber-900/20',
    ring: 'ring-2 ring-amber-700/30',
    avatarBg: 'bg-amber-900/20',
    avatarText: 'text-amber-500',
    label: 'text-amber-500',
    glow: '',
    rankNum: 'text-amber-500',
  },
}

export default async function LeaderboardPage() {
  const session = await getServerSession(authOptions)

  const [users, totalUsers] = await Promise.all([
    prisma.user.findMany({
      orderBy: { xp: 'desc' },
      take: 50,
      select: {
        id: true, username: true, xp: true, level: true,
        streakDays: true, plan: true,
        _count: { select: { scans: true, labAttempts: true } },
      },
    }),
    prisma.user.count(),
  ])

  const top3 = users.slice(0, 3)
  const rest  = users.slice(3)
  const myRank = users.findIndex(u => u.id === session?.user.id) + 1
  const me = users.find(u => u.id === session?.user.id)
  const totalXp = users.reduce((s, u) => s + u.xp, 0)

  return (
    <div>
      <Header
        title="Leaderboard"
        description="Top operators ranked by XP — knowledge is the real exploit."
      />

      {/* ── Platform stats strip ── */}
      <div className="grid grid-cols-3 gap-3 mb-8">
        {[
          { label: 'Total Operators', value: totalUsers.toLocaleString(), icon: Shield,       color: 'text-accent' },
          { label: 'XP in System',    value: totalXp.toLocaleString(),    icon: Zap,          color: 'text-warning' },
          { label: 'Your Rank',       value: myRank ? `#${myRank}` : '—', icon: Trophy,       color: 'text-accent' },
        ].map(s => {
          const Icon = s.icon
          return (
            <div key={s.label} className="card p-4 flex items-center gap-4">
              <div className="w-10 h-10 rounded-lg bg-surface-2 flex items-center justify-center flex-shrink-0">
                <Icon size={18} className={s.color} />
              </div>
              <div>
                <div className="text-xl font-bold font-mono text-ink">{s.value}</div>
                <div className="text-xs text-muted">{s.label}</div>
              </div>
            </div>
          )
        })}
      </div>

      {/* ── Podium (top 3) ── */}
      {top3.length > 0 && (
        <div className="mb-8">
          <div className="text-[11px] text-muted uppercase tracking-widest font-mono mb-4 text-center">
            — Top 3 Operators —
          </div>

          {/* Podium cards — arranged 2nd / 1st / 3rd */}
          <div className="flex gap-3 items-end justify-center">
            {([top3[1], top3[0], top3[2]] as (typeof top3[number] | undefined)[]).map((user, col) => {
              if (!user) return <div key={col} className="flex-1 max-w-[220px]" />
              const rank = col === 0 ? 2 : col === 1 ? 1 : 3
              const s    = PODIUM_STYLES[rank as 1 | 2 | 3]
              const prog = xpProgress(user.xp)
              const isMe = user.id === session?.user.id

              return (
                <div key={user.id} className={`flex-1 max-w-[220px] flex flex-col items-center ${s.order}`}>
                  {/* Rank medal */}
                  <div className="text-2xl mb-2">{MEDAL[rank - 1]}</div>

                  {/* Card */}
                  <div className={`w-full card ${s.ring} ${s.glow} p-4 flex flex-col items-center text-center relative overflow-hidden`}>
                    {isMe && (
                      <span className="absolute top-2 right-2 text-[10px] font-mono text-accent bg-accent/10 border border-accent/20 rounded px-1.5 py-0.5">you</span>
                    )}

                    {/* Avatar */}
                    <div className={`w-14 h-14 rounded-full ${s.avatarBg} ${s.ring} flex items-center justify-center mb-3 flex-shrink-0`}>
                      <span className={`text-2xl font-bold ${s.avatarText}`}>
                        {user.username.charAt(0).toUpperCase()}
                      </span>
                    </div>

                    <div className="font-bold text-ink truncate w-full text-sm">{user.username}</div>
                    {user.plan === 'PRO' && (
                      <span className="badge-blue text-[10px] mt-1">PRO</span>
                    )}

                    {/* XP */}
                    <div className={`text-3xl font-bold font-mono mt-3 ${s.label}`}>
                      {user.xp.toLocaleString()}
                    </div>
                    <div className="text-[11px] text-muted mb-3">XP</div>

                    {/* XP progress bar */}
                    <div className="w-full h-1.5 bg-border-2 rounded-full overflow-hidden mb-4">
                      <div
                        className={`h-full bg-gradient-to-r ${s.barColor} rounded-full`}
                        style={{ width: `${prog.pct}%` }}
                      />
                    </div>

                    {/* Stats grid */}
                    <div className="w-full grid grid-cols-3 gap-1.5 text-center">
                      <div className="bg-surface-2 rounded-lg p-2">
                        <div className="text-xs font-mono font-bold text-ink">Lv.{user.level}</div>
                        <div className="text-[10px] text-muted mt-0.5">Level</div>
                      </div>
                      <div className="bg-surface-2 rounded-lg p-2">
                        <div className="text-xs font-mono font-bold text-ink">{user._count.scans}</div>
                        <div className="text-[10px] text-muted mt-0.5">Scans</div>
                      </div>
                      <div className="bg-surface-2 rounded-lg p-2">
                        <div className="text-xs font-mono font-bold text-ink">{user._count.labAttempts}</div>
                        <div className="text-[10px] text-muted mt-0.5">Labs</div>
                      </div>
                    </div>

                    {user.streakDays > 0 && (
                      <div className="mt-3 flex items-center gap-1 text-xs text-warning font-mono">
                        <Flame size={12} className="flex-shrink-0" />
                        {user.streakDays}d streak
                      </div>
                    )}
                  </div>

                  {/* Podium bar */}
                  <div className={`w-full ${s.height} mt-1 rounded-b-lg bg-gradient-to-b ${s.barColor} opacity-60 podium-bar`} />
                </div>
              )
            })}
          </div>
        </div>
      )}

      {/* ── My rank banner (if not top 3) ── */}
      {me && myRank > 3 && (
        <div className="mb-4 card p-4 border-accent/25 bg-accent/5 flex items-center gap-4">
          <div className="w-10 h-10 rounded-full bg-accent/20 border border-accent/30 flex items-center justify-center text-accent font-bold text-sm flex-shrink-0">
            {me.username.charAt(0).toUpperCase()}
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-sm font-semibold text-ink">Your position</div>
            <div className="text-xs text-dim font-mono mt-0.5">
              {me.username} · {me.xp.toLocaleString()} XP · Lv.{me.level}
              {me.streakDays > 0 && ` · 🔥 ${me.streakDays}d`}
            </div>
          </div>
          <div className="text-right flex-shrink-0">
            <div className="text-2xl font-bold font-mono text-accent">#{myRank}</div>
            <div className="text-[10px] text-muted">rank</div>
          </div>
        </div>
      )}

      {/* ── Rank table (4th onwards) ── */}
      {rest.length > 0 && (
        <div className="card overflow-hidden">
          <div className="px-5 py-3 border-b border-border grid grid-cols-[2.5rem_1fr_6rem_5rem_4rem_4rem_4rem] gap-3 text-[11px] text-muted uppercase tracking-widest">
            <span>#</span>
            <span>Operator</span>
            <span className="text-right">XP</span>
            <span className="text-right">Level</span>
            <span className="text-right">Scans</span>
            <span className="text-right">Labs</span>
            <span className="text-right">Plan</span>
          </div>

          <div className="divide-y divide-border">
            {rest.map((user, i) => {
              const rank  = i + 4
              const isMe  = user.id === session?.user.id
              const prog  = xpProgress(user.xp)

              return (
                <div key={user.id}
                  className={`px-5 py-3 grid grid-cols-[2.5rem_1fr_6rem_5rem_4rem_4rem_4rem] gap-3 items-center transition-colors
                    ${isMe
                      ? 'bg-accent/5 border-l-2 border-l-accent'
                      : rank <= 10 ? 'hover:bg-surface-2/60' : 'hover:bg-surface-2/40'
                    }`}>

                  {/* Rank number */}
                  <div className={`text-sm font-mono text-center font-bold
                    ${rank <= 10 ? 'text-accent/70' : 'text-muted'}`}>
                    {rank}
                  </div>

                  {/* Operator */}
                  <div className="flex items-center gap-3 min-w-0">
                    <div className={`w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0
                      ${isMe ? 'bg-accent/20 border border-accent/30 text-accent' : 'bg-surface-2 border border-border text-dim'}`}>
                      {user.username.charAt(0).toUpperCase()}
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium text-ink truncate">{user.username}</span>
                        {isMe && <span className="text-[10px] font-mono text-accent flex-shrink-0">(you)</span>}
                      </div>
                      {/* Mini XP progress */}
                      <div className="flex items-center gap-2 mt-1">
                        <div className="flex-1 h-0.5 bg-border-2 rounded-full overflow-hidden">
                          <div
                            className="h-full bg-accent/50 rounded-full"
                            style={{ width: `${prog.pct}%` }}
                          />
                        </div>
                        {user.streakDays > 0 && (
                          <span className="text-[10px] text-warning font-mono flex-shrink-0">🔥{user.streakDays}</span>
                        )}
                      </div>
                    </div>
                  </div>

                  {/* XP */}
                  <div className="text-right font-mono text-sm text-ink font-semibold">
                    {user.xp.toLocaleString()}
                  </div>

                  {/* Level */}
                  <div className="text-right font-mono text-sm text-dim">Lv.{user.level}</div>

                  {/* Scans */}
                  <div className="text-right font-mono text-sm text-muted">{user._count.scans}</div>

                  {/* Labs */}
                  <div className="text-right font-mono text-sm text-muted">{user._count.labAttempts}</div>

                  {/* Plan */}
                  <div className="text-right">
                    {user.plan === 'PRO'
                      ? <span className="badge-blue text-[10px]">PRO</span>
                      : <span className="text-[10px] text-muted/40 font-mono">—</span>}
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      )}

      {users.length === 0 && (
        <div className="card p-16 text-center">
          <Trophy size={40} className="text-muted mx-auto mb-3 opacity-30" />
          <div className="text-dim font-medium">No operators yet</div>
          <div className="text-xs text-muted mt-1">Be the first to earn XP.</div>
        </div>
      )}
    </div>
  )
}

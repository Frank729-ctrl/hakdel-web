'use client'

import { useSession } from 'next-auth/react'
import Link from 'next/link'
import { Bell, Flame } from 'lucide-react'

interface HeaderProps { title: string; description?: string; action?: React.ReactNode }

export function Header({ title, description, action }: HeaderProps) {
  const { data: session } = useSession()
  const streak = session?.user?.streakDays ?? 0
  return (
    <header className="flex items-center justify-between mb-6">
      <div>
        <h1 className="text-xl font-semibold text-ink">{title}</h1>
        {description && <p className="text-sm text-muted mt-0.5">{description}</p>}
      </div>
      <div className="flex items-center gap-3">
        {streak > 0 && (
          <div className="flex items-center gap-1.5 px-2.5 py-1.5 bg-warning/10 border border-warning/20 rounded text-warning text-xs font-medium">
            <Flame size={13} />{streak}d streak
          </div>
        )}
        {action}
        <Link href="/notifications" className="w-8 h-8 bg-surface-2 border border-border rounded flex items-center justify-center text-dim hover:text-ink transition-colors">
          <Bell size={14} />
        </Link>
        <Link href="/profile">
          <div className="w-8 h-8 bg-accent rounded-full flex items-center justify-center text-white text-sm font-medium">
            {(session?.user?.name ?? session?.user?.username ?? 'U').charAt(0).toUpperCase()}
          </div>
        </Link>
      </div>
    </header>
  )
}

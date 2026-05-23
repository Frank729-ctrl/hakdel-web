'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { signOut, useSession } from 'next-auth/react'
import { cn, xpProgress } from '@/lib/utils'
import {
  LayoutDashboard, Shield, Wrench, FlaskConical, BookOpen,
  Trophy, User, Settings, Bell, LogOut, ChevronRight, AlertTriangle
} from 'lucide-react'

const nav = [
  { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { label: 'Scanner',   href: '/scanner',   icon: Shield },
  {
    label: 'Tools', href: '/tools', icon: Wrench,
    children: [
      { label: 'IP Reputation', href: '/tools/ip' },
      { label: 'Port Scanner',  href: '/tools/ports' },
      { label: 'HTTP Headers',  href: '/tools/headers' },
      { label: 'Domain Intel',  href: '/tools/domain' },
      { label: 'CVE Lookup',    href: '/tools/cve' },
      { label: 'Hash Check',    href: '/tools/hash' },
    ],
  },
  { label: 'Labs',       href: '/labs',        icon: FlaskConical },
  { label: 'Quiz',       href: '/quiz',        icon: BookOpen },
  { label: 'Incidents',  href: '/incidents',   icon: AlertTriangle },
  { label: 'Leaderboard',href: '/leaderboard', icon: Trophy },
]

export function Sidebar() {
  const pathname = usePathname()
  const { data: session } = useSession()
  const prog = xpProgress(session?.user?.xp ?? 0)
  const isActive = (href: string) => href === '/dashboard' ? pathname === href : pathname.startsWith(href)

  return (
    <aside className="w-[220px] h-screen bg-surface border-r border-border flex flex-col fixed left-0 top-0 z-30 overflow-y-auto">
      {/* Brand */}
      <div className="flex items-center gap-2.5 px-4 py-4 border-b border-border">
        <div className="w-7 h-7 bg-accent rounded flex items-center justify-center text-white font-bold text-xs flex-shrink-0">H</div>
        <span className="font-bold text-ink">HakDel</span>
        <span className={cn('badge text-[10px] ml-auto', session?.user?.plan === 'PRO' ? 'badge-blue' : 'badge-gray')}>
          {session?.user?.plan ?? 'FREE'}
        </span>
      </div>

      {/* Nav */}
      <nav className="flex-1 px-2 py-3 space-y-0.5">
        {nav.map((item) => {
          const Icon = item.icon
          const active = isActive(item.href)

          if (item.children) {
            const anyChildActive = item.children.some((c) => pathname === c.href)
            return (
              <div key={item.href}>
                <Link href={item.href} className={cn('nav-link', (active || anyChildActive) && 'text-ink')}>
                  <Icon size={15} />
                  <span>{item.label}</span>
                  <ChevronRight size={12} className="ml-auto opacity-40" />
                </Link>
                {anyChildActive && (
                  <div className="ml-6 mt-0.5 space-y-0.5">
                    {item.children.map((c) => (
                      <Link key={c.href} href={c.href}
                        className={cn('block px-3 py-1.5 rounded text-xs transition-colors',
                          pathname === c.href ? 'text-accent bg-accent/5' : 'text-muted hover:text-dim'
                        )}>
                        {c.label}
                      </Link>
                    ))}
                  </div>
                )}
              </div>
            )
          }

          return (
            <Link key={item.href} href={item.href} className={active ? 'nav-link-active' : 'nav-link'}>
              <Icon size={15} /><span>{item.label}</span>
            </Link>
          )
        })}
      </nav>

      {/* Bottom */}
      <div className="px-2 py-3 border-t border-border space-y-0.5">
        <div className="px-3 py-2 mb-1">
          <div className="flex items-center justify-between mb-1">
            <span className="text-xs text-muted">Level {prog.level}</span>
            <span className="text-xs font-mono text-dim">{prog.xp} XP</span>
          </div>
          <div className="h-1 bg-border-2 rounded-full overflow-hidden">
            <div className="h-full bg-accent rounded-full transition-all" style={{ width: `${prog.pct}%` }} />
          </div>
        </div>
        <Link href="/notifications" className={isActive('/notifications') ? 'nav-link-active' : 'nav-link'}><Bell size={15} /><span>Notifications</span></Link>
        <Link href="/profile"       className={isActive('/profile')       ? 'nav-link-active' : 'nav-link'}><User size={15} /><span>Profile</span></Link>
        <Link href="/settings"      className={isActive('/settings')      ? 'nav-link-active' : 'nav-link'}><Settings size={15} /><span>Settings</span></Link>
        <button onClick={() => signOut({ callbackUrl: '/login' })} className="nav-link w-full text-danger/70 hover:text-danger hover:bg-danger/10">
          <LogOut size={15} /><span>Sign out</span>
        </button>
      </div>
    </aside>
  )
}

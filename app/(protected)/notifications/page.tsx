import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'
import { prisma } from '@/lib/db'
import { Header } from '@/components/layout/header'
import { formatRelative } from '@/lib/utils'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Notifications' }

export default async function NotificationsPage() {
  const session = await getServerSession(authOptions)!
  const notifications = await prisma.notification.findMany({
    where: { userId: session!.user.id },
    orderBy: { createdAt: 'desc' },
    take: 50,
  })
  await prisma.notification.updateMany({
    where: { userId: session!.user.id, isRead: false },
    data: { isRead: true },
  })

  return (
    <div>
      <Header title="Notifications" />
      <div className="max-w-2xl">
        {notifications.length === 0 ? (
          <div className="card p-12 text-center">
            <div className="text-3xl mb-3">🔔</div>
            <div className="font-medium text-ink mb-1">All caught up</div>
            <p className="text-sm text-muted">No notifications yet.</p>
          </div>
        ) : (
          <div className="card divide-y divide-border">
            {notifications.map((n) => (
              <div key={n.id} className={`px-5 py-4 ${!n.isRead ? 'bg-accent/5' : ''}`}>
                <div className="flex items-start justify-between gap-4">
                  <div>
                    <div className="text-sm font-medium text-ink">{n.title}</div>
                    {n.message && <p className="text-xs text-dim mt-0.5">{n.message}</p>}
                  </div>
                  <div className="flex-shrink-0 text-xs text-muted font-mono">{formatRelative(n.createdAt)}</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

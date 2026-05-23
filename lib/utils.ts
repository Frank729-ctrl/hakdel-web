import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatDate(date: Date | string) {
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(date))
}

export function formatRelative(date: Date | string) {
  const now = Date.now()
  const then = new Date(date).getTime()
  const diff = now - then
  const minutes = Math.floor(diff / 60000)
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m ago`
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return formatDate(date)
}

export function scoreToGrade(score: number): { grade: string; color: string } {
  if (score >= 90) return { grade: 'A+', color: 'text-success' }
  if (score >= 80) return { grade: 'A', color: 'text-success' }
  if (score >= 70) return { grade: 'B', color: 'text-accent' }
  if (score >= 60) return { grade: 'C', color: 'text-warning' }
  if (score >= 50) return { grade: 'D', color: 'text-warning' }
  return { grade: 'F', color: 'text-danger' }
}

export function xpToLevel(xp: number): number {
  const thresholds = [0, 100, 280, 520, 820, 1200, 1650, 2150, 2750, 3400, 4150, 4950, 5850, 6800, 7850]
  let level = 1
  thresholds.forEach((t, i) => { if (xp >= t) level = i + 1 })
  return Math.min(level, 15)
}

export function xpProgress(xp: number) {
  const thresholds = [0, 100, 280, 520, 820, 1200, 1650, 2150, 2750, 3400, 4150, 4950, 5850, 6800, 7850]
  const level = xpToLevel(xp)
  const current = thresholds[level - 1] ?? 0
  const next = thresholds[level] ?? thresholds[thresholds.length - 1]
  const pct = next > current ? Math.round(((xp - current) / (next - current)) * 100) : 100
  return { level, xp, pct, current, next }
}

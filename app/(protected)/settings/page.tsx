'use client'

import { useState } from 'react'
import { useSession } from 'next-auth/react'
import { Header } from '@/components/layout/header'

export default function SettingsPage() {
  const { data: session } = useSession()
  const [currentPw, setCurrentPw] = useState('')
  const [newPw, setNewPw] = useState('')
  const [confirmPw, setConfirmPw] = useState('')
  const [pwLoading, setPwLoading] = useState(false)
  const [pwMsg, setPwMsg] = useState('')

  async function changePassword(e: React.FormEvent) {
    e.preventDefault()
    if (newPw !== confirmPw) { setPwMsg('Passwords do not match.'); return }
    if (newPw.length < 8) { setPwMsg('Password must be at least 8 characters.'); return }
    setPwLoading(true)
    const res = await fetch('/api/user/change-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ currentPassword: currentPw, newPassword: newPw }),
    })
    const data = await res.json()
    setPwLoading(false)
    setPwMsg(res.ok ? 'Password updated.' : data.error ?? 'Failed.')
    if (res.ok) { setCurrentPw(''); setNewPw(''); setConfirmPw('') }
  }

  return (
    <div>
      <Header title="Settings" />
      <div className="max-w-lg space-y-6">
        <div className="card p-5">
          <div className="section-title">Account</div>
          <div className="space-y-3 text-sm">
            <div className="flex justify-between">
              <span className="text-muted">Username</span>
              <span className="font-mono text-ink">{session?.user?.username}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted">Email</span>
              <span className="font-mono text-ink">{session?.user?.email}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted">Plan</span>
              <span className={session?.user?.plan === 'PRO' ? 'badge-blue' : 'badge-gray'}>{session?.user?.plan ?? 'FREE'}</span>
            </div>
          </div>
        </div>

        <div className="card p-5">
          <div className="section-title">Change Password</div>
          {pwMsg && (
            <div className={`mb-3 p-3 rounded text-sm ${pwMsg.includes('updated') ? 'bg-success/10 border border-success/20 text-success' : 'bg-danger/10 border border-danger/20 text-danger'}`}>
              {pwMsg}
            </div>
          )}
          <form onSubmit={changePassword} className="space-y-3">
            <div>
              <label className="label">Current password</label>
              <input className="input" type="password" value={currentPw} onChange={(e) => setCurrentPw(e.target.value)} required />
            </div>
            <div>
              <label className="label">New password</label>
              <input className="input" type="password" value={newPw} onChange={(e) => setNewPw(e.target.value)} required minLength={8} />
            </div>
            <div>
              <label className="label">Confirm new password</label>
              <input className="input" type="password" value={confirmPw} onChange={(e) => setConfirmPw(e.target.value)} required />
            </div>
            <button type="submit" className="btn-primary" disabled={pwLoading}>
              {pwLoading ? 'Updating…' : 'Update password'}
            </button>
          </form>
        </div>
      </div>
    </div>
  )
}

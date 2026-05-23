import { NextRequest, NextResponse } from 'next/server'
import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'

export async function POST(req: NextRequest) {
  const session = await getServerSession(authOptions)
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { query } = await req.json()
  if (!query) return NextResponse.json({ error: 'Query required' }, { status: 400 })

  const isCveId = /^CVE-\d{4}-\d+$/i.test(query.trim())
  const url = isCveId
    ? `https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=${query.trim().toUpperCase()}`
    : `https://services.nvd.nist.gov/rest/json/cves/2.0?keywordSearch=${encodeURIComponent(query)}&resultsPerPage=10`

  const headers: Record<string, string> = {}
  if (process.env.NVD_API_KEY) headers.apiKey = process.env.NVD_API_KEY

  try {
    const res = await fetch(url, { headers })
    return NextResponse.json(await res.json())
  } catch {
    return NextResponse.json({ error: 'NVD API request failed' }, { status: 500 })
  }
}

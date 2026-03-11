/**
 * BIOS Version Comparison Utility
 *
 * Handles version comparison across all major motherboard/OEM manufacturers.
 * Each brand uses a wildly different versioning scheme, so we detect the brand
 * from WMI fields and apply brand-specific parsing.
 *
 * Primary comparison: bios_release_date (universal, always reliable)
 * Secondary: manufacturer-specific version string parsing
 */

// ─── Brand Detection ───────────────────────────────────────────────

export type BiosBrand =
  | 'asus'
  | 'msi'
  | 'gigabyte'
  | 'asrock'
  | 'biostar'
  | 'evga'
  | 'dell'
  | 'hp'
  | 'lenovo'
  | 'acer'
  | 'toshiba'
  | 'supermicro'
  | 'intel'
  | 'unknown'

/**
 * Detect the board manufacturer brand from WMI fields.
 *
 * `bios_manufacturer` tells us who wrote the firmware (AMI, Insyde, Phoenix),
 * NOT who made the board. We need `motherboard_manufacturer` or `system_manufacturer`
 * to identify the actual brand, then fall back to version-string heuristics.
 */
export function detectBiosBrand(
  biosManufacturer: string | null,
  biosVersion: string | null,
  motherboardManufacturer?: string | null,
  systemManufacturer?: string | null,
): BiosBrand {
  // Combine all hints into one searchable string
  const hints = [
    motherboardManufacturer,
    systemManufacturer,
    biosManufacturer,
    biosVersion,
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()

  if (/\basus\b|republic of gamers|rog\b/.test(hints)) return 'asus'
  if (/\bmsi\b|micro[- ]?star/.test(hints)) return 'msi'
  if (/\bgigabyte\b|giga-byte|aorus/.test(hints)) return 'gigabyte'
  if (/\basrock\b/.test(hints)) return 'asrock'
  if (/\bbiostar\b/.test(hints)) return 'biostar'
  if (/\bevga\b/.test(hints)) return 'evga'
  if (/\bdell\b/.test(hints)) return 'dell'
  if (/\bhp\b|\bhewlett[- ]?packard\b/.test(hints)) return 'hp'
  if (/\blenovo\b/.test(hints)) return 'lenovo'
  if (/\bacer\b/.test(hints)) return 'acer'
  if (/\btoshiba\b|\bdynabook\b/.test(hints)) return 'toshiba'
  if (/\bsupermicro\b/.test(hints)) return 'supermicro'
  if (/\bintel\b/.test(hints) && !/\bamerican megatrends\b/.test(hints)) return 'intel'

  // Version-string heuristics when manufacturer fields are missing
  const v = (biosVersion ?? '').trim()
  if (/^F\d+/i.test(v)) return 'gigabyte' // F15, F31d
  if (/^A\d{2}$/.test(v)) return 'dell' // A04, A25
  if (/^P\d+\.\d+$|^L\d+\.\d+$/i.test(v)) return 'asrock' // P1.40, L2.62
  if (/^V\d+\.\d+$/.test(v)) return 'acer' // V1.08

  return 'unknown'
}

// ─── Parsed Version ────────────────────────────────────────────────

export interface ParsedBiosVersion {
  /** Original raw version string */
  raw: string
  /** Numeric sortable value (higher = newer) */
  numeric: number
  /** Whether this is a beta/pre-release */
  beta: boolean
  /** Human-friendly description of what was parsed */
  display: string
  /** Brand that was detected */
  brand: BiosBrand
}

// ─── Brand-Specific Parsers ────────────────────────────────────────

/**
 * ASUS: 4-digit integer, e.g. "0405", "1401", "2803", "3202"
 * Straight integer comparison.
 */
function parseAsus(v: string): ParsedBiosVersion {
  const num = parseInt(v, 10)
  return {
    raw: v,
    numeric: isNaN(num) ? 0 : num,
    beta: false,
    display: v,
    brand: 'asus',
  }
}

/**
 * MSI: "1.80", "E7C94AMS.180", "7D25vA6"
 * Extract trailing decimal version.
 */
function parseMsi(v: string): ParsedBiosVersion {
  // Try "X.XX" pattern directly
  const decMatch = v.match(/(\d+\.\d+)/)
  if (decMatch) {
    const num = parseFloat(decMatch[1])
    return { raw: v, numeric: num * 100, beta: false, display: decMatch[1], brand: 'msi' }
  }
  // Try trailing 3-digit number like ".180" → 1.80
  const trailMatch = v.match(/\.(\d{3})$/)
  if (trailMatch) {
    const num = parseInt(trailMatch[1], 10) / 100
    return { raw: v, numeric: num * 100, beta: false, display: num.toFixed(2), brand: 'msi' }
  }
  // Fallback: try any number
  const anyNum = v.match(/(\d+)/)
  return {
    raw: v,
    numeric: anyNum ? parseInt(anyNum[1], 10) : 0,
    beta: false,
    display: v,
    brand: 'msi',
  }
}

/**
 * Gigabyte: "F1", "F15", "F31d", "F32h", "FB"
 * F + integer, optional lowercase letter = beta.
 */
function parseGigabyte(v: string): ParsedBiosVersion {
  const m = v.match(/^F(\d+)([a-z])?$/i)
  if (m) {
    const major = parseInt(m[1], 10)
    const letter = m[2]?.toLowerCase() ?? null
    // Stable versions sort above beta of the same number
    // e.g. F31 (stable) > F31d (beta), F32 > F31
    const numeric = major * 100 + (letter ? letter.charCodeAt(0) - 96 : 99)
    return {
      raw: v,
      numeric,
      beta: !!letter,
      display: letter ? `F${major} (beta ${letter})` : `F${major}`,
      brand: 'gigabyte',
    }
  }
  // Legacy Award-era: "FB", "FC" — single letter
  const legacy = v.match(/^F([A-Z])$/i)
  if (legacy) {
    return {
      raw: v,
      numeric: legacy[1].toUpperCase().charCodeAt(0) - 64,
      beta: false,
      display: v,
      brand: 'gigabyte',
    }
  }
  return { raw: v, numeric: 0, beta: false, display: v, brand: 'gigabyte' }
}

/**
 * ASRock: "P1.40", "P1.50", "L2.62", "L8.01", or plain "4.03", "5.01"
 * Letter prefix + decimal version.
 */
function parseAsrock(v: string): ParsedBiosVersion {
  const m = v.match(/^([A-Z])?(\d+\.\d+)$/i)
  if (m) {
    const prefix = m[1]?.toUpperCase() ?? ''
    const decimal = parseFloat(m[2])
    // Prefix letter adds a generation offset: P=1, L=2, etc.
    // This is a heuristic — within same board, later letters = newer gen
    const prefixWeight = prefix ? (prefix.charCodeAt(0) - 64) * 10000 : 0
    return {
      raw: v,
      numeric: prefixWeight + decimal * 100,
      beta: false,
      display: v,
      brand: 'asrock',
    }
  }
  return { raw: v, numeric: 0, beta: false, display: v, brand: 'asrock' }
}

/**
 * Biostar: "VKB0618B", "VIT0721B"
 * 3-char model + 4-digit date code + suffix.
 */
function parseBiostar(v: string): ParsedBiosVersion {
  const m = v.match(/^[A-Z]{3}(\d{4})[A-Z]?$/i)
  if (m) {
    return {
      raw: v,
      numeric: parseInt(m[1], 10),
      beta: false,
      display: v,
      brand: 'biostar',
    }
  }
  return { raw: v, numeric: 0, beta: false, display: v, brand: 'biostar' }
}

/**
 * EVGA: "P08", "P09", "P10"
 * P + integer.
 */
function parseEvga(v: string): ParsedBiosVersion {
  const m = v.match(/^P(\d+)$/i)
  return {
    raw: v,
    numeric: m ? parseInt(m[1], 10) : 0,
    beta: false,
    display: v,
    brand: 'evga',
  }
}

/**
 * Dell: "A00", "A04", "A24", "A25"
 * A + integer, strictly sequential.
 */
function parseDell(v: string): ParsedBiosVersion {
  const m = v.match(/^A(\d+)$/i)
  return {
    raw: v,
    numeric: m ? parseInt(m[1], 10) : 0,
    beta: false,
    display: v,
    brand: 'dell',
  }
}

/**
 * HP: Legacy "F.01", "F.1B", "F.67" (hex after F.)
 *     Modern "01.06.00", "02.05.01" (semantic version)
 */
function parseHp(v: string): ParsedBiosVersion {
  // Modern: XX.XX.XX
  const semver = v.match(/^(\d+)\.(\d+)\.(\d+)$/)
  if (semver) {
    const num = parseInt(semver[1]) * 10000 + parseInt(semver[2]) * 100 + parseInt(semver[3])
    return { raw: v, numeric: num, beta: false, display: v, brand: 'hp' }
  }
  // Legacy: F.XX (hex)
  const legacy = v.match(/^F\.([0-9A-Fa-f]+)$/)
  if (legacy) {
    return {
      raw: v,
      numeric: parseInt(legacy[1], 16),
      beta: false,
      display: v,
      brand: 'hp',
    }
  }
  return { raw: v, numeric: 0, beta: false, display: v, brand: 'hp' }
}

/**
 * Lenovo: "N2XET27W (1.17)" — use parenthesized decimal
 *         "FBKTD7AUS" — middle 3 chars are the version
 */
function parseLenovo(v: string): ParsedBiosVersion {
  // Format with parenthesized version: "N2XET27W (1.17)"
  const paren = v.match(/\((\d+\.\d+)\)/)
  if (paren) {
    return {
      raw: v,
      numeric: parseFloat(paren[1]) * 100,
      beta: false,
      display: paren[1],
      brand: 'lenovo',
    }
  }
  // 9-char format: prefix(4) + version(3) + region(2) → "FBKTD7AUS"
  if (/^[A-Z0-9]{8,9}$/i.test(v) && v.length >= 7) {
    const verChars = v.substring(4, 7) // e.g. "D7A"
    // Convert alphanumeric 3-char code to sortable number
    let num = 0
    for (let i = 0; i < verChars.length; i++) {
      const c = verChars.charCodeAt(i)
      num = num * 36 + (c >= 48 && c <= 57 ? c - 48 : c >= 65 && c <= 90 ? c - 55 : c >= 97 && c <= 122 ? c - 87 : 0)
    }
    return { raw: v, numeric: num, beta: false, display: `${v} [${verChars}]`, brand: 'lenovo' }
  }
  return { raw: v, numeric: 0, beta: false, display: v, brand: 'lenovo' }
}

/**
 * Acer: "V1.08", "V2.01"
 */
function parseAcer(v: string): ParsedBiosVersion {
  const m = v.match(/^V?(\d+\.\d+)$/i)
  return {
    raw: v,
    numeric: m ? parseFloat(m[1]) * 100 : 0,
    beta: false,
    display: v,
    brand: 'acer',
  }
}

/**
 * Toshiba: "6.80", "6.90"
 */
function parseToshiba(v: string): ParsedBiosVersion {
  const m = v.match(/^(\d+\.\d+)$/)
  return {
    raw: v,
    numeric: m ? parseFloat(m[1]) * 100 : 0,
    beta: false,
    display: v,
    brand: 'toshiba',
  }
}

/**
 * Supermicro: "1.3", "3.6", "3.7"
 */
function parseSupermicro(v: string): ParsedBiosVersion {
  const m = v.match(/^(\d+)\.(\d+)$/)
  if (m) {
    return {
      raw: v,
      numeric: parseInt(m[1]) * 100 + parseInt(m[2]),
      beta: false,
      display: v,
      brand: 'supermicro',
    }
  }
  return { raw: v, numeric: 0, beta: false, display: v, brand: 'supermicro' }
}

/**
 * Intel NUC/Server: "JYGLKCPX.86A.0049.2019.0401.1038"
 * Build number is the 3rd segment; date is embedded in segments 4-5.
 */
function parseIntel(v: string): ParsedBiosVersion {
  const parts = v.split('.')
  if (parts.length >= 5) {
    const build = parseInt(parts[2], 10) || 0
    const year = parseInt(parts[3], 10) || 0
    const mmdd = parseInt(parts[4], 10) || 0
    // Use date as primary sort: YYYYMMDD * 10000 + build
    const numeric = year * 100000 + mmdd * 10 + build
    return {
      raw: v,
      numeric,
      beta: false,
      display: `Build ${parts[2]} (${parts[3]}.${parts[4]})`,
      brand: 'intel',
    }
  }
  return { raw: v, numeric: 0, beta: false, display: v, brand: 'intel' }
}

/**
 * Generic fallback: try decimal parse, then alphanumeric sort value.
 */
function parseGeneric(v: string): ParsedBiosVersion {
  // Try pure number
  const num = parseFloat(v)
  if (!isNaN(num)) {
    return { raw: v, numeric: num * 100, beta: false, display: v, brand: 'unknown' }
  }
  // Try extracting any number from the string
  const extracted = v.match(/(\d+(?:\.\d+)?)/)
  if (extracted) {
    return {
      raw: v,
      numeric: parseFloat(extracted[1]) * 100,
      beta: false,
      display: v,
      brand: 'unknown',
    }
  }
  // Alphanumeric hash for last resort
  let hash = 0
  for (let i = 0; i < v.length; i++) {
    hash = hash * 31 + v.charCodeAt(i)
  }
  return { raw: v, numeric: hash, beta: false, display: v, brand: 'unknown' }
}

// ─── Main Parser ───────────────────────────────────────────────────

const parsers: Record<BiosBrand, (v: string) => ParsedBiosVersion> = {
  asus: parseAsus,
  msi: parseMsi,
  gigabyte: parseGigabyte,
  asrock: parseAsrock,
  biostar: parseBiostar,
  evga: parseEvga,
  dell: parseDell,
  hp: parseHp,
  lenovo: parseLenovo,
  acer: parseAcer,
  toshiba: parseToshiba,
  supermicro: parseSupermicro,
  intel: parseIntel,
  unknown: parseGeneric,
}

/**
 * Parse a BIOS version string into a comparable structure.
 */
export function parseBiosVersion(
  version: string | null,
  biosManufacturer?: string | null,
  motherboardManufacturer?: string | null,
  systemManufacturer?: string | null,
): ParsedBiosVersion | null {
  if (!version) return null
  const v = version.trim()
  if (!v) return null

  const brand = detectBiosBrand(
    biosManufacturer ?? null,
    v,
    motherboardManufacturer,
    systemManufacturer,
  )
  return parsers[brand](v)
}

// ─── Release Date Parsing ──────────────────────────────────────────

/**
 * Parse a WMI CIM_DATETIME string into a Date object.
 * Format: "20240327000000.000000+000" → 2024-03-27
 *
 * Also handles already-formatted dates like "2024-03-27" or "03/27/2024".
 */
export function parseBiosReleaseDate(wmiDate: string | null): Date | null {
  if (!wmiDate) return null
  const d = wmiDate.trim()
  if (!d) return null

  // WMI CIM_DATETIME: YYYYMMDDHHMMSS.MMMMMM±OOO
  const wmi = d.match(/^(\d{4})(\d{2})(\d{2})/)
  if (wmi) {
    const date = new Date(parseInt(wmi[1]), parseInt(wmi[2]) - 1, parseInt(wmi[3]))
    return isNaN(date.getTime()) ? null : date
  }

  // ISO: 2024-03-27 or 2024/03/27
  const iso = d.match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})/)
  if (iso) {
    const date = new Date(parseInt(iso[1]), parseInt(iso[2]) - 1, parseInt(iso[3]))
    return isNaN(date.getTime()) ? null : date
  }

  // US format: MM/DD/YYYY
  const us = d.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/)
  if (us) {
    const date = new Date(parseInt(us[3]), parseInt(us[1]) - 1, parseInt(us[2]))
    return isNaN(date.getTime()) ? null : date
  }

  return null
}

/**
 * Format a BIOS release date for human display.
 * Input: WMI CIM_DATETIME or any parseable date string.
 * Output: "YYYY-MM-DD" or null.
 */
export function formatBiosDate(wmiDate: string | null): string | null {
  const d = parseBiosReleaseDate(wmiDate)
  if (!d) return null
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

// ─── Comparison ────────────────────────────────────────────────────

export interface BiosInfo {
  bios_manufacturer: string | null
  bios_version: string | null
  bios_release_date: string | null
  motherboard_manufacturer?: string | null
  system_manufacturer?: string | null
}

/**
 * Compare two BIOS versions. Returns:
 *  - negative if A is older than B
 *  - positive if A is newer than B
 *  - 0 if equal or indeterminate
 *
 * Primary comparison: release date (most reliable, universal).
 * Secondary: manufacturer-specific version parsing.
 */
export function compareBiosVersions(a: BiosInfo, b: BiosInfo): number {
  // Step 1: Compare by release date (most reliable)
  const dateA = parseBiosReleaseDate(a.bios_release_date)
  const dateB = parseBiosReleaseDate(b.bios_release_date)

  if (dateA && dateB) {
    const diff = dateA.getTime() - dateB.getTime()
    if (diff !== 0) return diff
  }

  // Step 2: Compare by version string (brand-specific)
  const parsedA = parseBiosVersion(
    a.bios_version,
    a.bios_manufacturer,
    a.motherboard_manufacturer,
    a.system_manufacturer,
  )
  const parsedB = parseBiosVersion(
    b.bios_version,
    b.bios_manufacturer,
    b.motherboard_manufacturer,
    b.system_manufacturer,
  )

  if (parsedA && parsedB && parsedA.brand === parsedB.brand) {
    return parsedA.numeric - parsedB.numeric
  }

  // Step 3: Fallback — prefer the one with a date
  if (dateA && !dateB) return 1
  if (!dateA && dateB) return -1

  return 0
}

/**
 * Human-friendly brand label for display.
 */
export function getBrandLabel(brand: BiosBrand): string {
  const labels: Record<BiosBrand, string> = {
    asus: 'ASUS',
    msi: 'MSI',
    gigabyte: 'Gigabyte',
    asrock: 'ASRock',
    biostar: 'Biostar',
    evga: 'EVGA',
    dell: 'Dell',
    hp: 'HP',
    lenovo: 'Lenovo',
    acer: 'Acer',
    toshiba: 'Toshiba',
    supermicro: 'Supermicro',
    intel: 'Intel',
    unknown: 'Unknown',
  }
  return labels[brand]
}

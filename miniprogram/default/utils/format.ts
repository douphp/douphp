/**
 * 时间 / 数字格式化工具。
 */

export function formatNumber(n: number): string {
  const s = n.toString()
  return s[1] ? s : '0' + s
}

/** 格式化为 "YYYY/M/D HH:MM:SS" */
export function formatTime(date: Date): string {
  const year = date.getFullYear()
  const month = date.getMonth() + 1
  const day = date.getDate()
  const hour = date.getHours()
  const minute = date.getMinutes()
  const second = date.getSeconds()

  return (
    [year, month, day].map(formatNumber).join('/') +
    ' ' +
    [hour, minute, second].map(formatNumber).join(':')
  )
}

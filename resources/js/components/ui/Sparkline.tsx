interface SparklineProps {
  values: (number | null)[];
  color?: string;
  height?: number;
  label: string;
}

/** Tiny trend line; null points are skipped. Sized to its container via viewBox. */
export function Sparkline({ values, color = '#2c6ecb', height = 36, label }: SparklineProps) {
  const width = 120;
  const points = values
    .map((value, index) => ({ value, index }))
    .filter((p): p is { value: number; index: number } => p.value !== null);

  if (points.length < 2) return null;

  const min = Math.min(...points.map((p) => p.value));
  const max = Math.max(...points.map((p) => p.value));
  const range = max - min || 1;
  const step = values.length > 1 ? width / (values.length - 1) : width;
  const coords = points.map((p) => [p.index * step, height - 3 - ((p.value - min) / range) * (height - 6)] as const);
  const line = coords.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
  const area = `${line} L${coords[coords.length - 1][0].toFixed(1)},${height} L${coords[0][0].toFixed(1)},${height} Z`;

  return (
    <svg width="100%" height={height} viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="img" aria-label={label} style={{ display: 'block', maxWidth: 120 }}>
      <path d={area} fill={color} opacity={0.12} />
      <path d={line} fill="none" stroke={color} strokeWidth={1.8} vectorEffect="non-scaling-stroke" />
    </svg>
  );
}

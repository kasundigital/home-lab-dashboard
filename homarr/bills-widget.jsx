<Stack gap="xs">
  <Group justify="space-between">
    <Text fw={700} c={data.summary.unpaid_count > 0 ? "red" : "green"}>To pay: {data.summary.unpaid_total_text}</Text>
    <Text size="sm" c="dimmed">Paid this month: {data.summary.paid_this_month_text}</Text>
  </Group>
  {data.bills.map((b) => (
    <Paper key={b.id} withBorder p="xs" radius="sm" style={{ borderLeft: "4px solid var(--mantine-color-" + b.color + "-6)" }}>
      <Group justify="space-between" wrap="nowrap">
        <Stack gap={0}>
          <Text size="sm" fw={600}>{b.provider} · {b.billing_month}</Text>
          <Text size="xs" c="dimmed">#{b.id} · {b.due_date ? "Due " + b.due_date : "No due date"}</Text>
        </Stack>
        <Group gap="xs" wrap="nowrap">
          <Text size="sm" fw={700}>{b.amount_text}</Text>
          <Badge color={b.color} variant="light">{b.state_label}</Badge>
        </Group>
      </Group>
    </Paper>
  ))}
</Stack>

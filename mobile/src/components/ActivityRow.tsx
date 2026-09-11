import { View, Text } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../constants/colors';
import { describeActivity, trimOwnName } from '../lib/activityLog';
import { SystemLogEntry } from '../types/api';
import { logClockTime, relativeLogTime } from '../lib/datetime';

/**
 * One activity row, rendered identically on the Activity Log and on the
 * dashboard's Recent Activity card. See src/lib/activityLog.ts for why the two
 * had to be merged.
 *
 * `variant` changes the CONTAINER ONLY — never the information. On its own
 * screen each row is a standing card; inside the dashboard's "Recent Activity"
 * card it is a divided list, because a bordered card nested inside a bordered
 * card reads as a rendering mistake. Title, category icon and colour, detail
 * line, relative time and exact clock time are the same on both.
 */
export function ActivityRow({
  item,
  userName,
  variant = 'card',
}: {
  item: SystemLogEntry;
  userName?: string;
  variant?: 'card' | 'plain';
}) {
  const { title, icon, tint, wash } = describeActivity(item);
  const detail = trimOwnName(item.description, userName);
  const plain = variant === 'plain';

  return (
    <View
      style={{
        flexDirection: 'row',
        gap: 12,
        ...(plain
          ? {
              paddingVertical: 11,
              borderBottomWidth: 1,
              borderBottomColor: colors.gray100,
            }
          : {
              marginHorizontal: 20,
              marginBottom: 8,
              padding: 12,
              borderRadius: 12,
              backgroundColor: colors.white,
              borderWidth: 1,
              borderColor: colors.gray200,
            }),
      }}
    >
      {/* Icon and colour carry the category, so a kind of event can be found
          by shape rather than by reading every title. */}
      <View
        style={{
          width: 32,
          height: 32,
          borderRadius: 16,
          backgroundColor: wash,
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <Ionicons name={icon} size={17} color={tint} />
      </View>

      <View style={{ flex: 1, minWidth: 0 }}>
        <View style={{ flexDirection: 'row', alignItems: 'flex-start', gap: 8 }}>
          <Text style={{ flex: 1, fontSize: 13, fontWeight: '600', color: colors.black }}>{title}</Text>
          {/* Relative time leads because it is what the question usually is;
              the exact clock time sits under it so nothing is lost. */}
          <Text style={{ fontSize: 10.5, color: colors.gray500, fontWeight: '500' }}>
            {relativeLogTime(item.logged_at)}
          </Text>
        </View>

        {detail ? (
          <Text style={{ fontSize: 11.5, color: colors.gray600, marginTop: 3, lineHeight: 16 }}>{detail}</Text>
        ) : null}

        <Text style={{ fontSize: 10, color: colors.gray400, marginTop: 4 }}>{logClockTime(item.logged_at)}</Text>
      </View>
    </View>
  );
}

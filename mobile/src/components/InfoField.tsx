import { View, Text } from 'react-native';
import { colors } from '../constants/colors';

export function InfoSectionTitle({ children }: { children: string }) {
  return (
    <Text
      style={{
        fontSize: 10,
        fontWeight: '700',
        color: colors.blue600,
        textTransform: 'uppercase',
        letterSpacing: 0.8,
        marginHorizontal: 20,
        marginTop: 20,
        marginBottom: 10,
      }}
    >
      {children}
    </Text>
  );
}

export function InfoField({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ paddingVertical: 10, paddingHorizontal: 20, borderBottomWidth: 1, borderBottomColor: colors.gray100 }}>
      <Text
        style={{
          fontSize: 10,
          color: colors.gray400,
          fontWeight: '500',
          textTransform: 'uppercase',
          letterSpacing: 0.5,
          marginBottom: 3,
        }}
      >
        {label}
      </Text>
      <Text style={{ fontSize: 14, color: colors.black, fontWeight: '500' }}>{value}</Text>
    </View>
  );
}

export function ProfileRow({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingVertical: 14,
        paddingHorizontal: 20,
        borderBottomWidth: 1,
        borderBottomColor: colors.gray100,
      }}
    >
      <Text style={{ fontSize: 13.5, color: colors.black, fontWeight: '500' }}>{label}</Text>
      <Text style={{ fontSize: 13, color: highlight ? colors.blue600 : colors.gray400, fontWeight: highlight ? '600' : '400' }}>
        {value}
      </Text>
    </View>
  );
}

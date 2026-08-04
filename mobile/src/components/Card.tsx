import { View, Text } from 'react-native';
import { colors } from '../constants/colors';

export function Card({
  title,
  children,
  style,
}: {
  title?: string;
  children: React.ReactNode;
  style?: object;
}) {
  return (
    <View
      style={[
        {
          backgroundColor: colors.white,
          borderRadius: 14,
          borderWidth: 1,
          borderColor: colors.gray200,
          padding: 18,
          marginHorizontal: 20,
          marginTop: 12,
        },
        style,
      ]}
    >
      {title ? (
        <Text style={{ fontSize: 13, fontWeight: '600', color: colors.black, marginBottom: 14 }}>
          {title}
        </Text>
      ) : null}
      {children}
    </View>
  );
}

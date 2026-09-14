import { useEffect, useRef, useState } from 'react';
import { Animated, Easing, AccessibilityInfo } from 'react-native';
import { Ionicons } from '@expo/vector-icons';

/**
 * The tab bar icon, with a press response.
 *
 * Tapping a tab used to change nothing but the tint — on a phone that reads as
 * a dead control, because the colour shift is the only feedback and it lands
 * at the same moment the whole screen swaps. This gives the tap an object to
 * respond to: the icon punches up, then settles slightly larger than the
 * inactive icons and rides 2px higher, so the active tab stays legible as a
 * state rather than only as a colour.
 *
 * IT KEYS OFF `focused`, NOT AN onPress HANDLER, and that is deliberate — the
 * tab bar owns the press, and a second handler would fire on taps the
 * navigator rejects (the already-active tab) while missing every focus change
 * that did not come from a tap (a deep link, a programmatic `router.replace`,
 * the hardware back button). Focus is the thing actually being signalled.
 *
 * `useNativeDriver` throughout: this runs on the UI thread, so it stays smooth
 * while the screen it just switched to is still mounting.
 */
export function TabBarIcon({
  name,
  color,
  focused,
  size = 20,
}: {
  name: keyof typeof Ionicons.glyphMap;
  color: string;
  focused: boolean;
  size?: number;
}) {
  const scale = useRef(new Animated.Value(focused ? 1.12 : 1)).current;
  const lift = useRef(new Animated.Value(focused ? -2 : 0)).current;
  const [reduceMotion, setReduceMotion] = useState(false);

  // Respect the OS setting rather than animating regardless: this fires on
  // every navigation, which is exactly the kind of repeated movement the
  // setting exists to switch off.
  useEffect(() => {
    let alive = true;
    AccessibilityInfo.isReduceMotionEnabled().then((on) => {
      if (alive) setReduceMotion(on);
    });
    const sub = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduceMotion);
    return () => {
      alive = false;
      sub.remove();
    };
  }, []);

  useEffect(() => {
    const restScale = focused ? 1.12 : 1;
    const restLift = focused ? -2 : 0;

    if (reduceMotion) {
      scale.setValue(restScale);
      lift.setValue(restLift);
      return;
    }

    Animated.parallel([
      focused
        ? // Overshoot THEN settle. A single spring to the resting size reads as
          // the icon growing; the overshoot is what makes it read as a tap
          // being answered.
          Animated.sequence([
            Animated.timing(scale, {
              toValue: 1.42,
              duration: 130,
              easing: Easing.out(Easing.quad),
              useNativeDriver: true,
            }),
            Animated.spring(scale, { toValue: restScale, friction: 4, tension: 150, useNativeDriver: true }),
          ])
        : Animated.spring(scale, { toValue: restScale, friction: 7, tension: 120, useNativeDriver: true }),
      Animated.spring(lift, { toValue: restLift, friction: 7, tension: 120, useNativeDriver: true }),
    ]).start();
  }, [focused, reduceMotion, scale, lift]);

  return (
    <Animated.View style={{ transform: [{ scale }, { translateY: lift }] }}>
      <Ionicons name={name} size={size} color={color} />
    </Animated.View>
  );
}

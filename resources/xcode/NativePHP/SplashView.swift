import SwiftUI

/// A view that replicates the launch screen appearance.
/// This allows the app to appear launched while heavy initialization continues in the background.
struct SplashView: View {
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        GeometryReader { geometry in
            ZStack {
                // Brand canvas behind the launch image — adapts to the device theme
                // so the splash background isn't a hard white in dark mode. The
                // LaunchImage asset itself resolves its own light/dark variant.
                (colorScheme == .dark
                    ? Color(red: 0.094, green: 0.071, blue: 0.063)
                    : Color(red: 0.965, green: 0.937, blue: 0.894))
                    .ignoresSafeArea()

                // LaunchImage scaled to fill (matching LaunchScreen.storyboard scaleAspectFill)
                Image("LaunchImage")
                    .resizable()
                    .aspectRatio(contentMode: .fill)
                    .frame(width: geometry.size.width, height: geometry.size.height)
                    .clipped()
                    .ignoresSafeArea()
            }
        }
        .ignoresSafeArea()
    }
}

#Preview {
    SplashView()
}

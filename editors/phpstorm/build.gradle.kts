// Build for the Laravel Warrant PhpStorm plugin.
//
// Uses the IntelliJ Platform Gradle Plugin 2.x. If any version below is no
// longer available when you build, bump it — IntelliJ will tell you the nearest
// valid value, and opening this folder in IntelliJ IDEA will resolve the rest.
//
// Common tasks:
//   ./gradlew runIde        launch a sandbox PhpStorm with this plugin loaded
//   ./gradlew buildPlugin   produce build/distributions/warrant-phpstorm-<ver>.zip
//   ./gradlew test          run the editor tests in src/test

plugins {
    id("java")
    id("org.jetbrains.kotlin.jvm") version "2.2.20"
    id("org.jetbrains.intellij.platform") version "2.1.0"
}

group = "com.warrant"
version = "0.9.0"

repositories {
    mavenCentral()
    intellijPlatform {
        defaultRepositories()
    }
}

dependencies {
    intellijPlatform {
        // The IDE we compile and run against. PhpStorm so that `runIde` gives
        // us real PHP heredocs to test injection in, and so the bundled PHP
        // plugin API is available to compile the injector.
        phpstorm("2024.2.4")

        // We reference PHP PSI (StringLiteralExpression) in the injector, and
        // the project's PHP interpreter when locating the language server's PHP.
        // The LSP API comes with the PhpStorm distribution itself.
        bundledPlugin("com.jetbrains.php")

        pluginVerifier()
        zipSigner()

        // `./gradlew test` types into a headless PhpStorm editor.
        testFramework(org.jetbrains.intellij.platform.gradle.TestFrameworkType.Platform)
    }

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.opentest4j:opentest4j:1.3.0")
}

intellijPlatform {
    // We have no GUI forms or @NotNull bytecode instrumentation to do, and the
    // instrumentation step needs an extra Java-compiler dependency — skip it.
    instrumentCode = false

    // The one settings page is small; skip the (slow) searchable-options
    // indexing, which only makes its labels findable in Settings search.
    buildSearchableOptions = false

    // `./gradlew verifyPlugin` checks the plugin against the baseline PhpStorm,
    // and also against a locally installed IDE when one is named, e.g.
    // `./gradlew verifyPlugin -PverifyLocalIde=/Applications/PhpStorm.app`.
    pluginVerification {
        ides {
            ide(org.jetbrains.intellij.platform.gradle.IntelliJPlatformType.PhpStorm, "2024.2.4")
            providers.gradleProperty("verifyLocalIde").orNull?.let { local(it) }
        }
    }

    pluginConfiguration {
        ideaVersion {
            // Oldest supported build (2024.2 == 242). Open upper bound so the
            // plugin installs in current and future PhpStorm versions (instead of
            // the plugin's default 242.* cap).
            sinceBuild = "242"
            untilBuild = provider { null }
        }
    }
}

// No Java toolchain is configured, so Gradle compiles with whatever JDK runs the
// build (IntelliJ's bundled JBR when launched from the IDE). We only pin the
// *bytecode* level to 21 so the plugin loads in PhpStorm, which runs on JBR 21.
java {
    sourceCompatibility = JavaVersion.VERSION_21
    targetCompatibility = JavaVersion.VERSION_21
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_21
    }
}

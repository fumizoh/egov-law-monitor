from models import PromptDocument, PromptSection


def render_section(section: PromptSection) -> str:
    """Render a prompt section as Markdown."""

    return f"## {section.title}\n\n{section.body}"


def render_system(document: PromptDocument) -> str:
    """Render the system prompt."""

    return document.system


def render_prompt(document: PromptDocument) -> str:
    """Render a complete Markdown prompt."""

    sections = "\n\n".join(
        render_section(section)
        for section in document.sections
    )

    return (
        f"# {document.title}\n\n"
        f"{render_system(document)}\n\n"
        f"{sections}"
    )
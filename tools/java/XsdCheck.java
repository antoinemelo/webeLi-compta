import java.io.File;
import javax.xml.XMLConstants;
import javax.xml.transform.stream.StreamSource;
import javax.xml.validation.Schema;
import javax.xml.validation.SchemaFactory;
import javax.xml.validation.Validator;

public final class XsdCheck {
    public static void main(String[] args) throws Exception {
        if (args.length < 1 || args.length > 2) {
            throw new IllegalArgumentException("usage: XsdCheck schema.xsd [document.xml]");
        }
        SchemaFactory factory = SchemaFactory.newInstance(
            XMLConstants.W3C_XML_SCHEMA_NS_URI
        );
        factory.setProperty(
            XMLConstants.ACCESS_EXTERNAL_DTD,
            ""
        );
        factory.setProperty(
            XMLConstants.ACCESS_EXTERNAL_SCHEMA,
            "file"
        );
        Schema schema = factory.newSchema(new File(args[0]));
        if (args.length == 2) {
            Validator validator = schema.newValidator();
            validator.setProperty(XMLConstants.ACCESS_EXTERNAL_DTD, "");
            validator.setProperty(XMLConstants.ACCESS_EXTERNAL_SCHEMA, "file");
            validator.validate(new StreamSource(new File(args[1])));
        }
        System.out.println("XSD valide");
    }
}
